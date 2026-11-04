(function () {
  "use strict";

  function setupLocationDialog() {
    var dialog = document.getElementById("boris-location-dialog");
    var form = document.getElementById("boris-location-dialog-form");
    var targetId = "";

    if (!dialog || !form || typeof borisStockAdmin === "undefined") {
      return;
    }

    document.addEventListener("click", function (event) {
      var button = event.target.closest ? event.target.closest(".boris-new-location") : null;
      if (!button) {
        return;
      }
      targetId = button.getAttribute("data-location-select") || "";
      form.reset();
      form.querySelector(".boris-dialog__message").textContent = "";
      dialog.showModal();
      form.elements.code.focus();
    });

    form.querySelectorAll(".boris-dialog__close, .boris-dialog__cancel").forEach(function (button) {
      button.addEventListener("click", function () {
        dialog.close();
      });
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var message = form.querySelector(".boris-dialog__message");
      var submit = form.querySelector('button[type="submit"], button:not([type])');
      var data = new FormData(form);
      data.append("action", "boris_stock_create_location");
      data.append("nonce", borisStockAdmin.locationNonce);
      message.textContent = borisStockAdmin.creating;
      submit.disabled = true;

      window
        .fetch(borisStockAdmin.ajaxUrl, {
          method: "POST",
          credentials: "same-origin",
          body: data,
        })
        .then(function (response) {
          return response.json();
        })
        .then(function (payload) {
          if (!payload.success) {
            throw new Error(
              payload.data && payload.data.message
                ? payload.data.message
                : borisStockAdmin.createFailed,
            );
          }
          document
            .querySelectorAll('[id^="boris-import-location-"]')
            .forEach(function (select) {
              var option = new Option(payload.data.code, payload.data.id, false, select.id === targetId);
              select.add(option);
            });
          var target = document.getElementById(targetId);
          if (target && !Array.prototype.some.call(target.options, function (option) { return option.value === String(payload.data.id); })) {
            target.add(new Option(payload.data.code, payload.data.id, true, true));
          } else if (target) {
            target.value = String(payload.data.id);
          }
          if (target) {
            target.dataset.locationVersion = String((parseInt(target.dataset.locationVersion || "0", 10) || 0) + 1);
            target.setCustomValidity("");
            var query = document.querySelector('.boris-location-query[data-location-select="' + targetId + '"]');
            if (query) {
              query.value = payload.data.code;
            }
          }
          dialog.close();
          if (target) {
            target.focus();
		  } else {
			window.location.reload();
          }
        })
        .catch(function (error) {
          message.textContent = error.message || borisStockAdmin.createFailed;
        })
        .finally(function () {
          submit.disabled = false;
        });
    });
  }

  function setupLocationSearch() {
    if (typeof borisStockAdmin === "undefined") {
      return;
    }
    var timers = new WeakMap();

    function refresh(input) {
      var selectId = input.getAttribute("data-location-select") || "";
      var select = document.getElementById(selectId);
      if (!select) {
        return;
      }
      var filter = document.querySelector('.boris-location-availability[data-location-select="' + selectId + '"]');
      var params = new URLSearchParams({
        action: "boris_stock_search_locations",
        nonce: borisStockAdmin.locationNonce,
        boris_product_id: input.getAttribute("data-boris-product") || "0",
        search: input.value || "",
        availability: filter ? filter.value : "all",
      });
      var selected = select.value;
      var requestVersion = (parseInt(select.dataset.locationVersion || "0", 10) || 0) + 1;
      select.dataset.locationVersion = String(requestVersion);
      select.disabled = true;
      window.fetch(borisStockAdmin.ajaxUrl + "?" + params.toString(), { credentials: "same-origin" })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (String(requestVersion) !== select.dataset.locationVersion) {
            return;
          }
          if (!payload.success || !Array.isArray(payload.data)) {
            throw new Error(borisStockAdmin.locationSearchFailed);
          }
          select.textContent = "";
          select.add(new Option("—", ""));
          var normalizedQuery = (input.value || "").trim().toLocaleUpperCase();
          var exactMatch = "";
          payload.data.forEach(function (row) {
            var label = row.code + " · " + row.quantity + " (" + row.available + " " + borisStockAdmin.freeStock + ")";
            select.add(new Option(label, row.id, false, String(row.id) === selected));
            if (normalizedQuery && String(row.code).trim().toLocaleUpperCase() === normalizedQuery) {
              exactMatch = String(row.id);
            }
          });
          if (exactMatch) {
            select.value = exactMatch;
          }
          select.setCustomValidity("");
        })
        .catch(function () {
          select.setCustomValidity(borisStockAdmin.locationSearchFailed);
        })
        .finally(function () {
          select.disabled = false;
        });
    }

    document.addEventListener("input", function (event) {
      var input = event.target.closest ? event.target.closest(".boris-location-query") : null;
      if (!input) {
        return;
      }
      window.clearTimeout(timers.get(input));
      timers.set(input, window.setTimeout(function () { refresh(input); }, 250));
    });
    document.addEventListener("change", function (event) {
      var filter = event.target.closest ? event.target.closest(".boris-location-availability") : null;
      if (!filter) {
        return;
      }
      var input = document.querySelector('.boris-location-query[data-location-select="' + filter.getAttribute("data-location-select") + '"]');
      if (input) {
        refresh(input);
      }
    });
  }

  function setupReceivingSuggestions() {
    document.querySelectorAll(".boris-receiving-product").forEach(function (productSelect) {
      var form = productSelect.closest("form");
      var locationSelect = form ? form.querySelector(".boris-receiving-location") : null;
      var message = form ? form.querySelector(".boris-suggestion") : null;
      var overview = form ? form.querySelector(".boris-receiving-overview") : null;
      var totalElement = form ? form.querySelector(".boris-receiving-total") : null;
      var list = form ? form.querySelector(".boris-receiving-locations") : null;
      if (!locationSelect) {
        return;
      }

      productSelect.addEventListener("change", function () {
        var productOption = productSelect.options[productSelect.selectedIndex];
        var borisProduct = productOption ? productOption.getAttribute("data-boris-product") || "0" : "0";
        var suggestion = productOption ? productOption.getAttribute("data-suggestion") || "0" : "0";
        var total = 0;
        var matchingLocations = [];

        Array.prototype.forEach.call(locationSelect.options, function (option) {
          var occupant = option.getAttribute("data-boris-product") || "0";
          option.disabled = option.value !== "" && occupant !== "0" && occupant !== borisProduct;
          if (option.value !== "" && occupant === borisProduct) {
            var quantity = parseInt(option.getAttribute("data-quantity") || "0", 10);
            var free = parseInt(option.getAttribute("data-free") || "0", 10);
            total += quantity;
            matchingLocations.push(option.textContent + " — " + quantity + " (" + free + " " + borisStockAdmin.freeStock + ")");
          }
        });

        if (overview && totalElement && list) {
          overview.hidden = borisProduct === "0";
          totalElement.textContent = borisStockAdmin.currentStock + " " + total;
          list.textContent = "";
          matchingLocations.forEach(function (locationText) {
            var item = document.createElement("li");
            item.textContent = locationText;
            list.appendChild(item);
          });
        }

        if (suggestion !== "0") {
          locationSelect.value = suggestion;
          if (message) {
            var suggestedOption = locationSelect.options[locationSelect.selectedIndex];
            var suggestedQuantity = parseInt(suggestedOption.getAttribute("data-quantity") || "0", 10);
            message.textContent = suggestedQuantity > 0 ? borisStockAdmin.suggestedStock : borisStockAdmin.suggestedLast;
          }
        } else {
          locationSelect.value = "";
          if (message) {
            message.textContent = "";
          }
        }
      });
    });
  }

  function mediaThumbnail(attachment) {
    var sizes = attachment.sizes || {};
    return sizes.thumbnail && sizes.thumbnail.url ? sizes.thumbnail.url : attachment.url;
  }

  function setupProductMedia() {
    var field = document.querySelector(".boris-product-photos");
    if (!field || typeof window.wp === "undefined" || !window.wp.media) {
      return;
    }
    var mainValue = field.querySelector(".boris-main-image-value");
    var galleryValue = field.querySelector(".boris-gallery-value");
    var preview = field.querySelector(".boris-media-preview");
    var choose = field.querySelector(".boris-media-choose");
    var clear = field.querySelector(".boris-media-clear");
    var folder = document.getElementById("boris-media-folder");
    var draggedId = 0;
    var mainId = parseInt(mainValue.value || "0", 10) || 0;
    var items = [];
    var mediaTree = Array.isArray(borisStockAdmin.mediaTree) ? borisStockAdmin.mediaTree.slice() : [];
    var selectedFolder = folder ? parseInt(folder.value || "0", 10) || 0 : 0;
    var expandedFolders = {};
    var activeFrame = null;

    preview.querySelectorAll(".boris-media-item").forEach(function (item) {
      items.push({
        id: parseInt(item.getAttribute("data-attachment-id") || "0", 10),
        url: item.getAttribute("data-thumbnail") || "",
        alt: item.getAttribute("data-alt") || "",
      });
    });

    function syncValues() {
      var ids = items.map(function (item) { return item.id; });
      if (ids.indexOf(mainId) === -1) {
        mainId = ids.length ? ids[0] : 0;
      }
      mainValue.value = mainId ? String(mainId) : "";
      galleryValue.value = ids.filter(function (id) { return id !== mainId; }).join(",");
    }

    function render() {
      syncValues();
      preview.textContent = "";
      items.forEach(function (item) {
        var card = document.createElement("article");
        var image = document.createElement("img");
        var controls = document.createElement("div");
        var main = document.createElement("button");
        var remove = document.createElement("button");
        var handle = document.createElement("span");
        card.className = "boris-media-item" + (item.id === mainId ? " is-main" : "");
        card.draggable = true;
        card.setAttribute("data-attachment-id", String(item.id));
        image.src = item.url;
        image.alt = item.alt;
        handle.className = "boris-media-drag";
        handle.textContent = "↕";
        handle.title = borisStockAdmin.dragPhoto;
        controls.className = "boris-media-item__actions";
        main.type = "button";
        main.className = "boris-btn boris-btn--small boris-make-main";
        main.textContent = item.id === mainId ? borisStockAdmin.mainPhoto : borisStockAdmin.makeMainPhoto;
        main.setAttribute("aria-pressed", item.id === mainId ? "true" : "false");
        main.addEventListener("click", function () {
          mainId = item.id;
          render();
        });
        remove.type = "button";
        remove.className = "boris-btn boris-btn--small";
        remove.textContent = borisStockAdmin.remove;
        remove.addEventListener("click", function () {
          items = items.filter(function (photo) { return photo.id !== item.id; });
          render();
        });
        card.addEventListener("dragstart", function () {
          draggedId = item.id;
          card.classList.add("is-dragging");
        });
        card.addEventListener("dragend", function () {
          draggedId = 0;
          card.classList.remove("is-dragging");
        });
        card.addEventListener("dragover", function (event) {
          event.preventDefault();
        });
        card.addEventListener("drop", function (event) {
          var from;
          var to;
          var moved;
          event.preventDefault();
          if (!draggedId || draggedId === item.id) {
            return;
          }
          from = items.findIndex(function (photo) { return photo.id === draggedId; });
          to = items.findIndex(function (photo) { return photo.id === item.id; });
          if (from < 0 || to < 0) {
            return;
          }
          moved = items.splice(from, 1)[0];
          items.splice(to, 0, moved);
          render();
        });
        controls.appendChild(main);
        controls.appendChild(remove);
        card.appendChild(handle);
        card.appendChild(image);
        card.appendChild(controls);
        preview.appendChild(card);
      });
    }

    function requestMediaAction(action, data, nonce) {
      var body = new window.URLSearchParams();
      body.set("action", action);
      body.set("nonce", nonce);
      Object.keys(data || {}).forEach(function (key) {
        var value = data[key];
        if (Array.isArray(value)) {
          value.forEach(function (item) {
            body.append(key + "[]", String(item));
          });
        } else {
          body.set(key, String(value));
        }
      });

      return window.fetch(borisStockAdmin.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
      }).then(function (response) {
        return response.json();
      }).then(function (response) {
        if (!response.success) {
          throw new Error(response.data && response.data.message ? response.data.message : borisStockAdmin.folderActionFailed);
        }
        return response.data || {};
      });
    }

    function folderById(id) {
      return mediaTree.find(function (entry) {
        return parseInt(entry.id, 10) === parseInt(id, 10);
      }) || null;
    }

    function folderLabel(id) {
      var entry = folderById(id);
      return entry ? entry.label : borisStockAdmin.allMedia;
    }

    function syncFolderSelect() {
      if (!folder) {
        return;
      }
      var current = String(selectedFolder);
      var known = Array.from(folder.options).some(function (option) {
        return option.value === current;
      });
      if (selectedFolder > 0 && !known) {
        folder.add(new Option(folderLabel(selectedFolder), current));
      }
      folder.value = current;
    }

    function rebuildFolderSelect() {
      if (!folder) {
        return;
      }
      folder.textContent = "";
      folder.add(new Option(borisStockAdmin.allMedia, "0"));
      mediaTree.forEach(function (entry) {
        folder.add(new Option(entry.label, String(entry.id)));
      });
      syncFolderSelect();
    }

    function setPanelMessage(message, isError) {
      if (!activeFrame) {
        return;
      }
      activeFrame.$el.find(".boris-media-tree__message").each(function () {
        this.textContent = message || "";
        this.classList.toggle("is-error", Boolean(isError));
      });
    }

    function refreshLibrary(frame) {
      var library = frame && frame.state() ? frame.state().get("library") : null;
      if (!library || !library.props) {
        return;
      }
      library.props.set({
        boris_stock_media_folder: selectedFolder > 0 ? selectedFolder : 0,
        boris_stock_media_refresh: Date.now(),
      });
    }

    function updateUploadDestination(frame) {
      if (!frame) {
        return;
      }
      frame.$el.find(".boris-media-upload-destination").text(
        borisStockAdmin.uploadDestination + " " + folderLabel(selectedFolder),
      );
    }

    function updateUploaderParams(frame) {
      var uploaderView = frame && frame.uploader ? frame.uploader.uploader : null;
      var uploader = uploaderView && uploaderView.uploader ? uploaderView.uploader : null;
      if (!uploader || !uploader.settings) {
        return;
      }
      uploader.settings.multipart_params = uploader.settings.multipart_params || {};
      uploader.settings.multipart_params.boris_stock_media_folder = selectedFolder > 0 ? selectedFolder : 0;
      uploader.settings.multipart_params.boris_stock_media_nonce = borisStockAdmin.mediaTreeNonce;
    }

    function setSelectedFolder(frame, id) {
      selectedFolder = parseInt(id || "0", 10) || 0;
      syncFolderSelect();
      refreshLibrary(frame);
      renderMediaTree(frame);
      updateUploadDestination(frame);
      updateUploaderParams(frame);
    }

    function replaceTree(tree) {
      mediaTree = Array.isArray(tree) ? tree.slice() : [];
      mediaTree.forEach(function (entry) {
        if (typeof expandedFolders[entry.id] === "undefined") {
          expandedFolders[entry.id] = true;
        }
      });
      if (selectedFolder > 0 && !folderById(selectedFolder)) {
        selectedFolder = 0;
      }
      rebuildFolderSelect();
    }

    function refreshTree(frame) {
      return requestMediaAction(
        "boris_stock_get_media_folders",
        {},
        borisStockAdmin.mediaTreeNonce,
      ).then(function (data) {
        replaceTree(data.tree);
        renderMediaTree(frame);
      }).catch(function (error) {
        setPanelMessage(error.message || borisStockAdmin.folderActionFailed, true);
      });
    }

    function buildTreeBranch(parentId, frame) {
      var list = document.createElement("ul");
      list.className = "boris-media-tree__branch";
      list.setAttribute("role", parentId === 0 ? "tree" : "group");
      mediaTree.filter(function (entry) {
        return parseInt(entry.parent, 10) === parentId;
      }).forEach(function (entry) {
        var id = parseInt(entry.id, 10);
        var children = mediaTree.some(function (candidate) {
          return parseInt(candidate.parent, 10) === id;
        });
        var item = document.createElement("li");
        var row = document.createElement("div");
        var toggle = document.createElement("button");
        var select = document.createElement("button");
        var icon = document.createElement("span");
        var label = document.createElement("span");

        item.className = "boris-media-tree__item";
        item.setAttribute("role", "treeitem");
        item.setAttribute("aria-selected", id === selectedFolder ? "true" : "false");
        row.className = "boris-media-tree__row";
        toggle.type = "button";
        toggle.className = "boris-media-tree__toggle";
        toggle.textContent = children ? (expandedFolders[id] === false ? "▸" : "▾") : "";
        toggle.disabled = !children;
        toggle.setAttribute("aria-label", entry.name);
        toggle.setAttribute("aria-expanded", children && expandedFolders[id] !== false ? "true" : "false");
        toggle.addEventListener("click", function () {
          expandedFolders[id] = expandedFolders[id] === false;
          renderMediaTree(frame);
        });
        select.type = "button";
        select.className = "boris-media-tree__folder" + (id === selectedFolder ? " is-selected" : "");
        select.title = entry.label;
        select.addEventListener("click", function () {
          setSelectedFolder(frame, id);
        });
        icon.className = "boris-media-tree__icon";
        icon.setAttribute("aria-hidden", "true");
        icon.textContent = "📁";
        label.textContent = entry.name;
        select.appendChild(icon);
        select.appendChild(label);
        row.appendChild(toggle);
        row.appendChild(select);
        item.appendChild(row);
        if (children && expandedFolders[id] !== false) {
          item.appendChild(buildTreeBranch(id, frame));
        }
        list.appendChild(item);
      });
      return list;
    }

    function renderMediaTree(frame) {
      var content;
      var panel;
      var heading;
      var all;
      var destination;
      var actions;
      var create;
      var move;
      var removeFolder;
      var creator;
      var name;
      var save;
      var cancel;
      var message;

      if (!frame || !frame.$el) {
        return;
      }
      content = frame.$el.find(".media-frame-content")[0];
      if (!content) {
        return;
      }
      content.classList.add("has-boris-media-tree");
      content.querySelectorAll(".boris-media-tree-panel").forEach(function (existing) {
        existing.remove();
      });

      panel = document.createElement("aside");
      panel.className = "boris-media-tree-panel";
      panel.setAttribute("aria-label", borisStockAdmin.photoFolders);
      heading = document.createElement("h2");
      heading.textContent = borisStockAdmin.photoFolders;
      all = document.createElement("button");
      all.type = "button";
      all.className = "boris-media-tree__all" + (selectedFolder === 0 ? " is-selected" : "");
      all.textContent = borisStockAdmin.allMedia;
      all.addEventListener("click", function () {
        setSelectedFolder(frame, 0);
      });
      panel.appendChild(heading);
      panel.appendChild(all);
      destination = document.createElement("p");
      destination.className = "boris-media-upload-destination";
      panel.appendChild(destination);
      if (mediaTree.length) {
        panel.appendChild(buildTreeBranch(0, frame));
      } else {
        var empty = document.createElement("p");
        empty.className = "boris-media-tree__empty";
        empty.textContent = borisStockAdmin.folderEmpty;
        panel.appendChild(empty);
      }

      actions = document.createElement("div");
      actions.className = "boris-media-tree__actions";
      create = document.createElement("button");
      create.type = "button";
      create.className = "button";
      create.textContent = selectedFolder > 0 ? borisStockAdmin.newSubfolder : borisStockAdmin.newRootFolder;
      move = document.createElement("button");
      move.type = "button";
      move.className = "button";
      move.textContent = borisStockAdmin.moveSelected;
      move.disabled = selectedFolder < 1;
      removeFolder = document.createElement("button");
      removeFolder.type = "button";
      removeFolder.className = "button-link-delete";
      removeFolder.textContent = borisStockAdmin.deleteFolder;
      removeFolder.disabled = selectedFolder < 1;
      actions.appendChild(create);
      actions.appendChild(move);
      actions.appendChild(removeFolder);
      panel.appendChild(actions);

      creator = document.createElement("div");
      creator.className = "boris-media-tree__creator";
      creator.hidden = true;
      name = document.createElement("input");
      name.type = "text";
      name.maxLength = 100;
      name.placeholder = borisStockAdmin.folderName;
      name.setAttribute("aria-label", borisStockAdmin.folderName);
      save = document.createElement("button");
      save.type = "button";
      save.className = "button button-primary";
      save.textContent = borisStockAdmin.createFolder;
      cancel = document.createElement("button");
      cancel.type = "button";
      cancel.className = "button-link";
      cancel.textContent = borisStockAdmin.cancel;
      creator.appendChild(name);
      creator.appendChild(save);
      creator.appendChild(cancel);
      panel.appendChild(creator);

      message = document.createElement("p");
      message.className = "boris-media-tree__message";
      message.setAttribute("aria-live", "polite");
      panel.appendChild(message);
      content.insertBefore(panel, content.firstChild);

      create.addEventListener("click", function () {
        creator.hidden = false;
        name.focus();
      });
      cancel.addEventListener("click", function () {
        creator.hidden = true;
        name.value = "";
      });
      save.addEventListener("click", function () {
        if (!name.value.trim()) {
          name.focus();
          return;
        }
        save.disabled = true;
        setPanelMessage(borisStockAdmin.creatingFolder, false);
        requestMediaAction(
          "boris_stock_create_media_folder",
          { name: name.value, parent_id: selectedFolder },
          borisStockAdmin.folderNonce,
        ).then(function (data) {
          replaceTree(data.tree);
          selectedFolder = parseInt(data.id, 10) || 0;
          syncFolderSelect();
          refreshLibrary(frame);
          renderMediaTree(frame);
          setPanelMessage(borisStockAdmin.folderCreated, false);
        }).catch(function (error) {
          setPanelMessage(error.message || borisStockAdmin.folderFailed, true);
        }).finally(function () {
          save.disabled = false;
        });
      });
      move.addEventListener("click", function () {
        var selection = frame.state().get("selection");
        var ids = selection ? selection.pluck("id").map(function (id) { return parseInt(id, 10); }).filter(Boolean) : [];
        if (selectedFolder < 1) {
          setPanelMessage(borisStockAdmin.folderChooseFirst, true);
          return;
        }
        if (!ids.length) {
          setPanelMessage(borisStockAdmin.mediaChooseFirst, true);
          return;
        }
        move.disabled = true;
        requestMediaAction(
          "boris_stock_assign_media_folder",
          { folder_id: selectedFolder, attachment_ids: ids },
          borisStockAdmin.mediaTreeNonce,
        ).then(function (data) {
          replaceTree(data.tree);
          refreshLibrary(frame);
          renderMediaTree(frame);
          setPanelMessage(borisStockAdmin.folderMoved, false);
        }).catch(function (error) {
          setPanelMessage(error.message || borisStockAdmin.folderActionFailed, true);
        }).finally(function () {
          move.disabled = false;
        });
      });
      removeFolder.addEventListener("click", function () {
        if (selectedFolder < 1 || !window.confirm(borisStockAdmin.folderDeleteConfirm)) {
          return;
        }
        removeFolder.disabled = true;
        requestMediaAction(
          "boris_stock_delete_media_folder",
          { folder_id: selectedFolder },
          borisStockAdmin.mediaTreeNonce,
        ).then(function (data) {
          selectedFolder = 0;
          replaceTree(data.tree);
          refreshLibrary(frame);
          renderMediaTree(frame);
          setPanelMessage(borisStockAdmin.folderDeleted, false);
        }).catch(function (error) {
          setPanelMessage(error.message || borisStockAdmin.folderActionFailed, true);
        }).finally(function () {
          removeFolder.disabled = false;
        });
      });
      updateUploadDestination(frame);
    }

    function bindUploadDestination(frame) {
      if (!window.wp.Uploader || !window.wp.Uploader.queue) {
        return;
      }
      function uploadAdded(attachment) {
        var destination = selectedFolder;
        var assigned = false;
        if (destination < 1) {
          return;
        }
        function assignWhenReady() {
          var id = parseInt(attachment.id || attachment.get("id") || "0", 10);
          if (assigned || !id || attachment.get("uploading")) {
            return;
          }
          assigned = true;
          attachment.off("change:id change:uploading", assignWhenReady);
          requestMediaAction(
            "boris_stock_assign_media_folder",
            { folder_id: destination, attachment_ids: [id] },
            borisStockAdmin.mediaTreeNonce,
          ).then(function (data) {
            replaceTree(data.tree);
            if (selectedFolder === destination) {
              refreshLibrary(frame);
            }
            setPanelMessage(borisStockAdmin.folderUploaded, false);
          }).catch(function (error) {
            setPanelMessage(error.message || borisStockAdmin.folderActionFailed, true);
          });
        }
        attachment.on("change:id change:uploading", assignWhenReady);
        assignWhenReady();
      }
      window.wp.Uploader.queue.on("add", uploadAdded);
      frame.on("close", function () {
        window.wp.Uploader.queue.off("add", uploadAdded);
      });
    }

    choose.addEventListener("click", function () {
      var library = { type: "image" };
      if (folder && parseInt(folder.value || "0", 10) > 0) {
        library.boris_stock_media_folder = parseInt(folder.value, 10);
      }
      var frame = window.wp.media({
        title: borisStockAdmin.choosePhotos,
        button: { text: borisStockAdmin.usePhotos },
        library: library,
        multiple: "add",
      });
      activeFrame = frame;
      frame.$el.addClass("boris-stock-media-frame");
      frame.on("content:render:browse content:render:upload", function () {
        window.setTimeout(function () {
          renderMediaTree(frame);
        }, 0);
      });
      frame.on("uploader:ready", function () {
        updateUploaderParams(frame);
      });
      frame.on("open", function () {
        var selection = frame.state().get("selection");
        items.forEach(function (item) {
          selection.add(window.wp.media.attachment(item.id));
        });
        renderMediaTree(frame);
        updateUploaderParams(frame);
        refreshTree(frame);
      });
      frame.on("select", function () {
        var seen = {};
        items = frame.state().get("selection").toJSON().map(function (attachment) {
          return {
            id: parseInt(attachment.id, 10),
            url: mediaThumbnail(attachment),
            alt: attachment.alt || attachment.title || "",
          };
        }).filter(function (item) {
          if (!item.id || seen[item.id]) {
            return false;
          }
          seen[item.id] = true;
          return true;
        });
        render();
      });
      bindUploadDestination(frame);
      frame.open();
    });

    clear.addEventListener("click", function () {
      items = [];
      mainId = 0;
      render();
    });

    var form = document.getElementById("boris-new-product-form");
    if (form) {
      form.addEventListener("submit", function (event) {
        if (items.length && !mainId) {
          event.preventDefault();
          event.stopImmediatePropagation();
          window.alert(borisStockAdmin.photoRequired);
        }
      }, true);
    }
    render();
  }

  function setupPhotoFolders() {
    var select = document.getElementById("boris-media-folder");
    var path = document.getElementById("boris-media-folder-path");
    var create = document.getElementById("boris-create-media-folder");
    var useSuggestion = document.getElementById("boris-use-folder-suggestion");
    var suggestion = document.getElementById("boris-folder-suggestion");
    var message = document.getElementById("boris-folder-message");
    var productName = document.querySelector('input[name="product_name"]');
    var categories = document.getElementById("boris-product-categories");
    if (!select || !path || !create || !useSuggestion || !suggestion || !message || !productName || !categories) {
      return;
    }

    function cleanSegment(value) {
      return String(value || "").replace(/[\\/]+/g, "-").trim();
    }

    function suggestedPath() {
      var selectedCategory = categories.options[categories.selectedIndex];
      return [
        cleanSegment(borisStockAdmin.siteName),
        selectedCategory ? cleanSegment(selectedCategory.textContent) : "",
        cleanSegment(productName.value),
      ].filter(function (segment) { return segment !== ""; }).join(" / ");
    }

    function refreshSuggestion() {
      var value = suggestedPath();
      suggestion.textContent = value ? borisStockAdmin.suggestedPath + " " + value : "";
      return value;
    }

    productName.addEventListener("input", refreshSuggestion);
    categories.addEventListener("change", refreshSuggestion);
    useSuggestion.addEventListener("click", function () {
      path.value = refreshSuggestion();
      path.focus();
    });
    create.addEventListener("click", function () {
      var body = new window.URLSearchParams();
      var buttonLabel = create.getAttribute("data-label") || create.textContent;
      create.setAttribute("data-label", buttonLabel);
      body.set("action", "boris_stock_create_media_folder");
      body.set("nonce", borisStockAdmin.folderNonce);
      body.set("path", path.value);
      create.disabled = true;
      create.textContent = borisStockAdmin.creatingFolder;
      message.textContent = "";
      window.fetch(borisStockAdmin.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
      }).then(function (response) {
        return response.json();
      }).then(function (response) {
        if (!response.success) {
          throw new Error(response.data && response.data.message ? response.data.message : borisStockAdmin.folderFailed);
        }
        var id = String(response.data.id);
        var option = Array.from(select.options).find(function (item) { return item.value === id; });
        if (!option) {
          option = document.createElement("option");
          option.value = id;
          select.appendChild(option);
        }
        option.textContent = response.data.label;
        select.value = id;
        message.textContent = borisStockAdmin.folderCreated;
      }).catch(function (error) {
        message.textContent = error.message || borisStockAdmin.folderFailed;
      }).finally(function () {
        create.disabled = false;
        create.textContent = buttonLabel;
      });
    });
    refreshSuggestion();
  }

  function setupProductAllocations() {
    var form = document.getElementById("boris-new-product-form");
    var rows = document.getElementById("boris-allocation-rows");
    var template = document.getElementById("boris-allocation-template");
    var add = document.getElementById("boris-add-allocation");
    var stock = document.getElementById("boris-opening-stock");
    var total = document.getElementById("boris-allocation-total");
    if (!form || !rows || !template || !add || !stock || !total) {
      return;
    }

    function refreshTotal() {
      var allocated = 0;
      rows.querySelectorAll(".boris-allocation-quantity").forEach(function (input) {
        allocated += parseInt(input.value || "0", 10) || 0;
      });
      var opening = parseInt(stock.value || "0", 10) || 0;
      var message = "";
      total.classList.remove("is-valid", "is-invalid");
      if (allocated > opening) {
        message = borisStockAdmin.allocationTooHigh;
        total.classList.add("is-invalid");
      } else if (allocated < opening) {
        message = borisStockAdmin.allocationTooLow;
        total.classList.add("is-invalid");
      } else {
        message = borisStockAdmin.allocationMatches;
        total.classList.add("is-valid");
      }
      total.textContent = borisStockAdmin.locationTotal + " " + allocated + " / " + opening + " · " + message;
      stock.setCustomValidity(allocated === opening ? "" : message);
    }

    function bindRow(row) {
      var remove = row.querySelector(".boris-remove-allocation");
      var quantity = row.querySelector(".boris-allocation-quantity");
      if (remove) {
        remove.addEventListener("click", function () {
          row.remove();
          refreshTotal();
        });
      }
      if (quantity) {
        quantity.addEventListener("input", refreshTotal);
      }
    }

    rows.querySelectorAll(".boris-allocation-row").forEach(bindRow);
    stock.addEventListener("input", refreshTotal);
    add.addEventListener("click", function () {
      var fragment = template.content.cloneNode(true);
      var row = fragment.querySelector(".boris-allocation-row");
	  var select = row.querySelector(".boris-location-select");
	  var query = row.querySelector(".boris-location-query");
	  if (select && query) {
	    var uniqueId = "boris-allocation-location-" + Date.now() + "-" + rows.children.length;
	    select.id = uniqueId;
	    query.setAttribute("data-location-select", uniqueId);
	    var createLocation = row.querySelector(".boris-new-location");
	    if (createLocation) {
	      createLocation.setAttribute("data-location-select", uniqueId);
	    }
	  }
      bindRow(row);
      rows.appendChild(fragment);
      row.querySelector("select").focus();
      refreshTotal();
    });

    form.addEventListener("submit", function () {
      var submit = form.querySelector('button[type="submit"]');
      if (submit) {
        submit.disabled = true;
        submit.textContent = borisStockAdmin.savingProduct;
      }
    });
    refreshTotal();
  }

  function setupCatalogMediaOrganization() {
    var panel = document.getElementById("boris-catalog-media-progress");
    if (!panel || panel.getAttribute("data-active") !== "1" || typeof borisStockAdmin === "undefined") {
      return;
    }

    var message = panel.querySelector(".boris-catalog-media-progress__message");
    var progress = panel.querySelector("progress");
    var processed = panel.querySelector(".boris-catalog-media-progress__processed");
    var total = panel.querySelector(".boris-catalog-media-progress__total");
    var running = false;

    function processNextBatch() {
      var body;
      if (running) {
        return;
      }
      running = true;
      body = new window.URLSearchParams();
      body.set("action", "boris_stock_process_product_photo_organization");
      body.set("nonce", borisStockAdmin.catalogMediaNonce);
      message.textContent = borisStockAdmin.organizingPhotos;

      window.fetch(borisStockAdmin.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
      }).then(function (response) {
        return response.json();
      }).then(function (response) {
        if (!response.success) {
          throw new Error(response.data && response.data.message ? response.data.message : borisStockAdmin.organizeFailed);
        }
        var done = parseInt(response.data.processed || "0", 10);
        var maximum = parseInt(response.data.total || "0", 10);
        processed.textContent = String(done);
        total.textContent = String(maximum);
        progress.max = Math.max(1, maximum);
        progress.value = done;
        if (response.data.complete) {
          message.textContent = borisStockAdmin.organizeComplete;
          window.setTimeout(function () {
            window.location.reload();
          }, 500);
          return;
        }
        running = false;
        window.setTimeout(processNextBatch, 100);
      }).catch(function (error) {
        message.textContent = error.message || borisStockAdmin.organizeFailed;
        panel.classList.add("boris-card--error");
        running = false;
      });
    }

    processNextBatch();
  }

  document.addEventListener("DOMContentLoaded", function () {
    setupLocationDialog();
    setupLocationSearch();
    setupReceivingSuggestions();
    setupPhotoFolders();
    setupProductMedia();
    setupProductAllocations();
    setupCatalogMediaOrganization();
  });
})();
