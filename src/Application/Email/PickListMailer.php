<?php
/**
 * Idempotent warehouse email for complete pick-list allocations.
 *
 * @package BorisSystems\Stock
 */

namespace BorisSystems\Stock\Application\Email;

use BorisSystems\Stock\Domain\Operations\IdempotencyKey;
use BorisSystems\Stock\Infrastructure\Database\Repository;
use BorisSystems\Stock\Infrastructure\Database\Tables;
use BorisSystems\Stock\Infrastructure\Database\Transaction;

defined( 'ABSPATH' ) || exit;

// Mail state must be read uncached and claimed under the plugin lock table.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
// Translated exception messages are data; the receiving UI escapes them for its concrete output context.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class PickListMailer {
	private Repository $repository;
	private Transaction $transaction;

	public function __construct( ?Repository $repository = null, ?Transaction $transaction = null ) {
		$this->repository  = $repository ?? new Repository();
		$this->transaction = $transaction ?? new Transaction();
	}

	/** Send automatically only when explicitly enabled. */
	public function maybe_send_automatically( int $picklist_id ): void {
		if ( 'yes' !== get_option( 'boris_stock_auto_email_picklist', 'no' ) ) {
			return;
		}

		try {
			$this->send( $picklist_id, false );
		} catch ( \Throwable $throwable ) {
			$this->record_failure( $picklist_id, '', $throwable->getMessage(), 'automatic' );
		}
	}

	/**
	 * Send a privacy-minimal test through the same WordPress mail route.
	 *
	 * No settings, operation records or problem records are changed.
	 */
	public function send_test( string $recipient ): bool {
		$recipient = sanitize_email( $recipient );
		if ( ! is_email( $recipient ) ) {
			return false;
		}

		try {
			return wp_mail(
				$recipient,
				__( 'BORIS Stock test email', 'boris-stock-inventory-management' ),
				__( 'This is a test email from BORIS Stock. Your warehouse email configuration is working.', 'boris-stock-inventory-management' ),
				array( 'Content-Type: text/plain; charset=UTF-8' )
			);
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Send a pick list once, or explicitly retry a failed/uncertain send.
	 *
	 * @return string One of sent, already_sent, in_progress, failed or uncertain.
	 */
	public function send( int $picklist_id, bool $retry = false, string $override_recipient = '' ): string {
		$recipient = '' !== $override_recipient ? sanitize_email( $override_recipient ) : sanitize_email( (string) get_option( 'boris_stock_warehouse_email', '' ) );
		if ( ! is_email( $recipient ) ) {
			throw new \RuntimeException( __( 'Configure a valid warehouse email address first.', 'boris-stock-inventory-management' ) );
		}

		$data = $this->picklist_data( $picklist_id );
		$key  = IdempotencyKey::make( 'picklist-mail', $picklist_id );
		$this->repository->operation(
			$key,
			'PICKLIST_MAIL',
			'picklist',
			$picklist_id,
			array(
				'picklist_id' => $picklist_id,
				'recipient'   => $recipient,
			)
		);

		$claim = $this->claim( $key, $retry, $recipient );
		if ( 'claimed' !== $claim ) {
			return $claim;
		}

		$subject = sprintf(
			/* translators: %s is a WooCommerce order number. */
			__( 'BORIS Stock pick list for order #%s', 'boris-stock-inventory-management' ),
			$data['order_number']
		);
		$message = $this->message( $data );
		$error   = '';
		try {
			$sent = wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		} catch ( \Throwable $throwable ) {
			$sent  = false;
			$error = $throwable->getMessage();
		}

		if ( $sent ) {
			if ( ! $this->finish( $key, 'COMMITTED', $recipient, '' ) ) {
				$this->record_failure(
					$picklist_id,
					$recipient,
					__( 'WordPress accepted the email, but confirmation could not be stored safely.', 'boris-stock-inventory-management' ),
					'confirmation'
				);
				return 'uncertain';
			}
			$this->repository->resolve_problem( $this->problem_key( $picklist_id ) );
			return 'sent';
		}

		$error = '' !== $error ? $error : __( 'WordPress could not hand off the warehouse email to the mail server.', 'boris-stock-inventory-management' );
		$this->finish( $key, 'FAILED', $recipient, $error );
		$this->record_failure( $picklist_id, $recipient, $error, $retry ? 'retry' : 'manual-or-automatic' );
		$this->notify_problem_address( $data, $error );
		return 'failed';
	}

	/** Return the durable send status for the pick-list UI. */
	public function status( int $picklist_id ): string {
		global $wpdb;
		$table = Tables::get( 'operations' );
		$key   = IdempotencyKey::make( 'picklist-mail', $picklist_id );
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE operation_key=%s', $table, $key ) );
	}

	/** Mark abandoned sends uncertain; never retry them automatically. */
	public function recover_stale_sends(): void {
		global $wpdb;
		$table = Tables::get( 'operations' );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id,entity_id,operation_key FROM %i WHERE operation_type='PICKLIST_MAIL' AND status='SENDING' AND updated_at_gmt<%s LIMIT 25", $table, $stale )
		);
		foreach ( $rows as $row ) {
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status='UNCERTAIN',last_error=%s,updated_at_gmt=%s WHERE id=%d AND status='SENDING'",
					$table,
					__( 'The email operation was interrupted; delivery cannot be confirmed safely.', 'boris-stock-inventory-management' ),
					Repository::now(),
					(int) $row->id
				)
			);
			if ( 1 === $changed ) {
				$this->record_failure(
					(int) $row->entity_id,
					'',
					__( 'The email status is uncertain. The pick list was not resent automatically.', 'boris-stock-inventory-management' ),
					'recovery'
				);
			}
		}
	}

	/** Claim the unique operation under a deterministic database lock. */
	private function claim( string $key, bool $retry, string $recipient ): string {
		return $this->transaction->run(
			function () use ( $key, $retry, $recipient ): string {
				global $wpdb;
				$table = Tables::get( 'operations' );
				$this->transaction->lock_resources( array( 'mail:' . $key ) );
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s FOR UPDATE', $table, $key ) );
				if ( ! $row ) {
					throw new \RuntimeException( __( 'The email operation could not be recorded.', 'boris-stock-inventory-management' ) );
				}
				if ( 'COMMITTED' === $row->status ) {
					return 'already_sent';
				}
				if ( 'SENDING' === $row->status ) {
					return 'in_progress';
				}
				if ( in_array( $row->status, array( 'FAILED', 'UNCERTAIN' ), true ) && ! $retry ) {
					return strtolower( (string) $row->status );
				}
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE %i SET status='SENDING',payload_json=%s,payload_hash=%s,last_error='',attempt_count=attempt_count+1,updated_at_gmt=%s WHERE id=%d",
						$table,
						wp_json_encode(
							array(
								'picklist_id' => (int) $row->entity_id,
								'recipient'   => $recipient,
							)
						),
						hash(
							'sha256',
							wp_json_encode(
								array(
									'picklist_id' => (int) $row->entity_id,
									'recipient'   => $recipient,
								)
							)
						),
						Repository::now(),
						(int) $row->id
					)
				);
				return 'claimed';
			}
		);
	}

	/** Confirm the observed result without ever resending inside this update. */
	private function finish( string $key, string $status, string $recipient, string $error ): bool {
		global $wpdb;
		$table   = Tables::get( 'operations' );
		$result  = array(
			'recipient'   => $recipient,
			'observed_at' => Repository::now(),
			'wp_mail'     => 'COMMITTED' === $status ? 'accepted' : 'failed',
		);
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status=%s,result_json=%s,last_error=%s,updated_at_gmt=%s WHERE operation_key=%s AND status='SENDING'",
				$table,
				$status,
				wp_json_encode( $result ),
				$error,
				Repository::now(),
				$key
			)
		);
		return 1 === $changed;
	}

	/** Read and verify that this is a complete executable pick list. */
	private function picklist_data( int $picklist_id ): array {
		global $wpdb;
		$t        = Tables::all();
		$picklist = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p.*,o.id AS boris_order_id,o.order_number_snapshot,o.boris_status FROM %i p INNER JOIN %i o ON o.id=p.boris_order_id WHERE p.id=%d',
				$t['picklists'],
				$t['orders'],
				$picklist_id
			)
		);
		if ( ! $picklist || ! in_array( $picklist->status, array( 'READY', 'PICKING' ), true ) || 'PROBLEM' === $picklist->boris_status ) {
			throw new \RuntimeException( __( 'Only a complete, processable pick list can be emailed.', 'boris-stock-inventory-management' ) );
		}

		$lines    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sku_snapshot,name_snapshot,reserved_quantity,required_quantity,location_code_snapshot FROM %i WHERE picklist_id=%d ORDER BY location_code_snapshot,sku_snapshot,id',
				$t['picklist_lines'],
				$picklist_id
			),
			ARRAY_A
		);
		$ordered  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(ordered_quantity),0) FROM %i WHERE boris_order_id=%d', $t['order_lines'], (int) $picklist->boris_order_id ) );
		$reserved = 0;
		foreach ( $lines as $line ) {
			if ( (int) $line['reserved_quantity'] !== (int) $line['required_quantity'] ) {
				throw new \RuntimeException( __( 'The pick list is not fully allocated and will not be emailed.', 'boris-stock-inventory-management' ) );
			}
			$reserved += (int) $line['reserved_quantity'];
		}
		if ( empty( $lines ) || $ordered !== $reserved ) {
			throw new \RuntimeException( __( 'The pick list is not fully allocated and will not be emailed.', 'boris-stock-inventory-management' ) );
		}

		return array(
			'picklist_id'    => $picklist_id,
			'display_number' => (string) $picklist->display_number,
			'order_number'   => (string) $picklist->order_number_snapshot,
			'lines'          => $lines,
			'url'            => add_query_arg(
				array(
					'page'        => 'boris-stock-picklists',
					'picklist_id' => $picklist_id,
				),
				admin_url( 'admin.php' )
			),
		);
	}

	/** Build a privacy-minimal plain-text warehouse message. */
	private function message( array $data ): string {
		$rows = array(
			__( 'BORIS Stock warehouse pick list', 'boris-stock-inventory-management' ),
			'',
			/* translators: %s is a WooCommerce order number. */
			sprintf( __( 'WooCommerce order: #%s', 'boris-stock-inventory-management' ), $data['order_number'] ),
			/* translators: %s is a BORIS Stock pick-list number. */
			sprintf( __( 'Pick list: %s', 'boris-stock-inventory-management' ), $data['display_number'] ),
			'',
		);
		foreach ( $data['lines'] as $line ) {
			$rows[] = sprintf(
				/* translators: 1: SKU, 2: product name, 3: quantity, 4: warehouse location. */
				__( 'SKU: %1$s | Product: %2$s | Quantity: %3$d | Location: %4$s', 'boris-stock-inventory-management' ),
				$line['sku_snapshot'],
				$line['name_snapshot'],
				(int) $line['reserved_quantity'],
				$line['location_code_snapshot']
			);
		}
		$rows[] = '';
		$rows[] = __( 'Open the protected pick list in WordPress:', 'boris-stock-inventory-management' );
		$rows[] = $data['url'];
		return implode( "\n", $rows );
	}

	private function record_failure( int $picklist_id, string $recipient, string $error, string $source ): void {
		$this->repository->problem(
			$this->problem_key( $picklist_id ),
			'PICKLIST_EMAIL_FAILED',
			__( 'The pick list email was not confirmed. The order and pick list remain unchanged; check the email settings and use “Resend pick list”.', 'boris-stock-inventory-management' ),
			array(
				'recipient' => $recipient,
				'error'     => $error,
				'source'    => $source,
			),
			array( 'picklist_id' => $picklist_id )
		);
	}

	private function notify_problem_address( array $data, string $error ): void {
		$recipient = sanitize_email( (string) get_option( 'boris_stock_problem_email', '' ) );
		if ( ! is_email( $recipient ) ) {
			return;
		}
		/* translators: %s is a BORIS Stock pick-list number. */
		$subject = sprintf( __( 'BORIS Stock: email issue for %s', 'boris-stock-inventory-management' ), $data['display_number'] );
		$message = implode(
			"\n",
			array(
				__( 'The warehouse email could not be confirmed.', 'boris-stock-inventory-management' ),
				/* translators: %s is a BORIS Stock pick-list number. */
				sprintf( __( 'Pick list: %s', 'boris-stock-inventory-management' ), $data['display_number'] ),
				/* translators: %s is an email failure message. */
				sprintf( __( 'Error: %s', 'boris-stock-inventory-management' ), $error ),
				$data['url'],
			)
		);
		try {
			wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		} catch ( \Throwable ) {
			// The persistent primary-mail problem remains the source of truth.
			return;
		}
	}

	private function problem_key( int $picklist_id ): string {
		return hash( 'sha256', 'picklist-email-failed:' . $picklist_id );
	}
}
