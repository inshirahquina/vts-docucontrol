/**
 * BulkSelect — list-level select mode component
 *
 * Markup (inside .list-controls-card, below search/filter):
 *   .bulk-list-toolbar
 *     #selectAllBulk + .bulk-mode-label   → "Select Files" / "Select All"
 *     #bulkStripCount
 *     #bulkExitBtn                       → Cancel (exits mode)
 *
 * Items: .bulk-item[data-selectable="1"] + .bulk-checkbox
 * Sticky action bar: #bulkToolbar + #bulkSelectedCount + #cancelSelectionBtn
 */
(function (window, document) {
    'use strict';

    function formatCount(n, noun, compact) {
        if (compact) {
            return n === 0 ? 'None selected' : (n + ' selected');
        }

        var singular = noun;
        var plural = noun;

        if (noun === 'request' || noun === 'requests') {
            singular = 'request';
            plural = 'requests';
        } else if (noun === 'file' || noun === 'files') {
            singular = 'file';
            plural = 'files';
        } else if (noun.endsWith('s')) {
            singular = noun.slice(0, -1);
            plural = noun;
        } else {
            singular = noun;
            plural = noun + 's';
        }

        if (n === 1) return '✓ 1 ' + singular + ' selected';
        return '✓ ' + n + ' ' + plural + ' selected';
    }

    function defaultIdleLabel(noun) {
        if (noun === 'request' || noun === 'requests') return 'Select Requests';
        return 'Select Files';
    }

    function BulkSelectController(opts) {
        this.opts = Object.assign({
            root: document,
            exitBtn: '#bulkExitBtn',
            clearBtn: '#cancelSelectionBtn',
            selectAll: '#selectAllBulk',
            modeLabel: '.bulk-mode-label',
            listToolbar: '.bulk-list-toolbar',
            itemSelector: '.bulk-item[data-selectable="1"]',
            checkboxSelector: '.bulk-checkbox',
            toolbar: '#bulkToolbar',
            countEl: '#bulkSelectedCount',
            stripCountEl: '#bulkStripCount',
            selectedClass: 'is-selected',
            modeClass: 'select-mode-active',
            bodyModeClass: 'has-select-mode',
            toolbarVisibleClass: 'bulk-toolbar-visible',
            modeContainer: null,
            noun: 'file',
            idleLabel: null,
            activeLabel: 'Select All',
            onChange: null
        }, opts || {});

        if (!this.opts.idleLabel) {
            this.opts.idleLabel = defaultIdleLabel(this.opts.noun);
        }

        this.root = this._el(this.opts.root) || document;
        this.modeContainer = this._el(this.opts.modeContainer) || this.root;
        this.exitBtn = this._el(this.opts.exitBtn);
        this.clearBtn = this._el(this.opts.clearBtn);
        this.selectAll = this._el(this.opts.selectAll);
        this.modeLabel = this.root.querySelector(this.opts.modeLabel)
            || document.querySelector(this.opts.modeLabel);
        this.listToolbar = this._el(this.opts.listToolbar)
            || this.root.querySelector(this.opts.listToolbar);
        this.toolbar = this._el(this.opts.toolbar);
        this.countEls = this._els(this.opts.countEl);
        this.stripCountEls = this._els(this.opts.stripCountEl);
        this.active = false;
        this._ignoreSelectAllChange = false;

        this._bind();
        this._syncChrome();
    }

    BulkSelectController.prototype._el = function (sel) {
        if (!sel) return null;
        if (typeof sel !== 'string') return sel;
        return document.querySelector(sel);
    };

    BulkSelectController.prototype._els = function (sel) {
        if (!sel) return [];
        if (typeof sel !== 'string') {
            return sel.nodeType ? [sel] : Array.prototype.slice.call(sel);
        }
        return Array.prototype.slice.call(document.querySelectorAll(sel));
    };

    BulkSelectController.prototype._items = function () {
        return Array.prototype.slice.call(this.root.querySelectorAll(this.opts.itemSelector));
    };

    BulkSelectController.prototype._checkboxes = function () {
        var self = this;
        return Array.prototype.slice.call(this.root.querySelectorAll(this.opts.checkboxSelector))
            .filter(function (cb) {
                return !self.selectAll || cb !== self.selectAll;
            });
    };

    BulkSelectController.prototype._setModeLabel = function (text) {
        if (this.modeLabel) this.modeLabel.textContent = text;
    };

    BulkSelectController.prototype._syncChrome = function () {
        this._setModeLabel(this.active ? this.opts.activeLabel : this.opts.idleLabel);

        if (this.exitBtn) {
            this.exitBtn.hidden = !this.active;
        }

        if (this.listToolbar) {
            this.listToolbar.classList.toggle('is-selecting', this.active);
        }

        if (this.selectAll && !this.active) {
            this._ignoreSelectAllChange = true;
            this.selectAll.checked = false;
            this.selectAll.indeterminate = false;
            this._ignoreSelectAllChange = false;
        }
    };

    BulkSelectController.prototype._bind = function () {
        var self = this;

        if (this.exitBtn) {
            this.exitBtn.addEventListener('click', function (e) {
                e.preventDefault();
                self.exitMode();
            });
        }

        if (this.clearBtn) {
            this.clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                self.clearSelection();
                self._update();
            });
        }

        this.root.addEventListener('change', function (e) {
            if (!self.active) return;
            var t = e.target;
            if (!t.matches || !t.matches(self.opts.checkboxSelector)) return;
            self._syncItem(t);
            self._update();
        });

        this.root.addEventListener('click', function (e) {
            if (!self.active) return;
            var item = e.target.closest(self.opts.itemSelector);
            if (!item) return;
            if (e.target.closest('a, button, form, label, select, input, .action-buttons, .per-row-actions, .no-bulk-toggle, .bulk-list-toolbar, .dropdown-modern, .dropdown-filter, .dropdown-content, .dropdown-trigger, .dropdown-btn')) {
                return;
            }
            var cb = item.querySelector(self.opts.checkboxSelector);
            if (!cb || cb.disabled) return;
            cb.checked = !cb.checked;
            self._syncItem(cb);
            self._update();
        });

        if (this.selectAll) {
            this.selectAll.addEventListener('click', function (e) {
                if (self.active) return;
                e.preventDefault();
                self.enterMode();
            });

            this.selectAll.addEventListener('change', function () {
                if (self._ignoreSelectAllChange) return;

                if (!self.active) {
                    self.enterMode();
                    return;
                }

                var checked = self.selectAll.checked;
                self._checkboxes().forEach(function (cb) {
                    if (cb.disabled) return;
                    cb.checked = checked;
                    self._syncItem(cb);
                });
                self._update();
            });
        }

        if (this.modeLabel) {
            this.modeLabel.addEventListener('click', function (e) {
                if (self.active) return;
                e.preventDefault();
                self.enterMode();
            });
        }
    };

    BulkSelectController.prototype._syncItem = function (cb) {
        var item = cb.closest(this.opts.itemSelector);
        if (!item) return;
        item.classList.toggle(this.opts.selectedClass, !!cb.checked);
    };

    BulkSelectController.prototype.enterMode = function () {
        if (this.active) return;
        this.active = true;
        if (this.modeContainer) this.modeContainer.classList.add(this.opts.modeClass);
        document.body.classList.add(this.opts.bodyModeClass);
        this.clearSelection();
        this._syncChrome();
        this._update();
    };

    BulkSelectController.prototype.exitMode = function () {
        if (!this.active) return;
        this.active = false;
        this.clearSelection();
        if (this.modeContainer) this.modeContainer.classList.remove(this.opts.modeClass);
        document.body.classList.remove(this.opts.bodyModeClass);
        document.body.classList.remove(this.opts.toolbarVisibleClass);
        this._syncChrome();
        this._update();
    };

    BulkSelectController.prototype.clearSelection = function () {
        var self = this;
        this._checkboxes().forEach(function (cb) {
            cb.checked = false;
            self._syncItem(cb);
        });
        if (this.selectAll) {
            this._ignoreSelectAllChange = true;
            this.selectAll.checked = false;
            this.selectAll.indeterminate = false;
            this._ignoreSelectAllChange = false;
        }
    };

    BulkSelectController.prototype.getSelectedIds = function () {
        return this._checkboxes()
            .filter(function (cb) { return cb.checked && !cb.disabled; })
            .map(function (cb) { return cb.value; });
    };

    BulkSelectController.prototype._update = function () {
        var ids = this.getSelectedIds();
        var count = ids.length;
        var total = this._checkboxes().filter(function (cb) { return !cb.disabled; }).length;
        var full = formatCount(count, this.opts.noun, false);
        var compact = this.active
            ? (count === 0 ? 'None selected' : (count + ' selected'))
            : 'None selected';

        this.countEls.forEach(function (el) { el.textContent = full; });
        this.stripCountEls.forEach(function (el) { el.textContent = compact; });

        if (this.toolbar) {
            var show = this.active && count > 0;
            this.toolbar.classList.toggle('is-visible', show);
            this.toolbar.setAttribute('aria-hidden', show ? 'false' : 'true');
            document.body.classList.toggle(this.opts.toolbarVisibleClass, show);
        } else {
            document.body.classList.remove(this.opts.toolbarVisibleClass);
        }

        if (this.selectAll && this.active) {
            this._ignoreSelectAllChange = true;
            this.selectAll.checked = total > 0 && count === total;
            this.selectAll.indeterminate = count > 0 && count < total;
            this._ignoreSelectAllChange = false;
        }

        if (typeof this.opts.onChange === 'function') {
            this.opts.onChange(count, ids);
        }
    };

    BulkSelectController.prototype.fillForm = function (form) {
        if (!form) return false;
        var holder = form.hasAttribute('data-bulk-ids')
            ? form
            : (form.querySelector('[data-bulk-ids]') || form);

        holder.querySelectorAll('input[name="request_ids[]"]').forEach(function (n) {
            n.remove();
        });

        var ids = this.getSelectedIds();
        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'request_ids[]';
            input.value = id;
            holder.appendChild(input);
        });
        return ids.length > 0;
    };

    window.BulkSelect = {
        init: function (opts) {
            return new BulkSelectController(opts);
        }
    };
})(window, document);
