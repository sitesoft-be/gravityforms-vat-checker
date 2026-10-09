/* global jQuery, vatChecker */
(function (root, factory) {
  const api = factory();
  root.SitesoftEuVat = api;
  if (typeof module === "object" && module.exports) {
    module.exports = api;
  }
})(typeof window !== "undefined" ? window : this, function () {
  "use strict";

  const FIELD = ".sitesoft-euvat-field";
  const COUNTRY = "select[name='country_code']";
  const MESSAGE = ".vat-message";
  const STATE_CLASSES =
    "vat-valid vat-invalid vat-temporary-error vat-checking";

  const STATUS = {
    VALID: "valid",
    INVALID: "invalid",
    TEMPORARY: "temporary_error",
  };

  // While typing, only check once the number looks complete. Other countries
  // (or incomplete numbers) are checked on blur / country change.
  const COMPLETE_WHILE_TYPING = {
    BE: /^[01]\d{9}$/,
    DE: /^\d{9}$/,
  };

  const STRIP = /[\s  -​‐-―−  　﻿.\-_/,]+/g;

  // Same rules as VAT_Number::from_input() in PHP.
  function normalize(countryCode, value) {
    let country = String(countryCode || "")
      .trim()
      .toUpperCase();
    if (country === "GR") {
      country = "EL";
    }

    let number = String(value || "")
      .toUpperCase()
      .replace(STRIP, "");

    const prefixes = country === "EL" ? ["EL", "GR"] : [country];
    for (const prefix of prefixes) {
      if (prefix && number.length > 2 && number.indexOf(prefix) === 0) {
        number = number.slice(2);
        break;
      }
    }

    return { country, number, key: number ? country + "|" + number : "" };
  }

  function init($, settings, options) {
    const opts = $.extend(
      {
        debounceMs: 800,
        ajax: (request) => $.ajax(request),
        setTimeout: (fn, ms) => window.setTimeout(fn, ms),
        clearTimeout: (id) => window.clearTimeout(id),
        root: document,
      },
      options || {},
    );

    const temporaryMessage =
      (settings.messages && settings.messages.temporary_error) ||
      "VAT number verification is temporarily unavailable. Please try again in a few moments.";

    // Final answers (valid/invalid) per country|number for this page view.
    const answers = new Map();

    function stateOf(input) {
      let state = input.data("euvatState");
      if (!state) {
        state = {
          seq: 0,
          timer: null,
          xhr: null,
          inFlightKey: "",
          shownKey: "",
          shownStatus: "",
        };
        input.data("euvatState", state);
      }
      return state;
    }

    function containerOf(input) {
      return input.closest(".gfield");
    }

    function read(input) {
      const country = containerOf(input).find(COUNTRY).val();
      return normalize(country, input.val());
    }

    function cancelTimer(state) {
      if (state.timer) {
        opts.clearTimeout(state.timer);
        state.timer = null;
      }
    }

    function abortRequest(state) {
      // Bumping seq guarantees a late response of the old request is ignored,
      // even when abort() is not possible anymore.
      state.seq++;
      if (state.xhr && typeof state.xhr.abort === "function") {
        state.xhr.abort();
      }
      state.xhr = null;
      state.inFlightKey = "";
    }

    function clearStatus(input) {
      const state = stateOf(input);
      const container = containerOf(input);

      state.shownKey = "";
      state.shownStatus = "";

      input.removeClass(STATE_CLASSES);
      input.data("vat-valid", false);
      input.attr("aria-invalid", "false");
      container.find(".sitesoft-euvat-token").val("");
      container.find(MESSAGE).remove();
      container.find(".icon-wrapper").css("display", "none");
      container.find(".icon-wrapper > div").css("display", "none");
    }

    function showIcon(container, name) {
      const wrapper = container.find(".icon-wrapper");
      wrapper.find("> div").css("display", "none");
      wrapper
        .css("display", "block")
        .find("." + name)
        .css("display", "block");
    }

    function showMessage(container, status, text) {
      const message = $("<div></div>")
        .addClass("gfield_description vat-message")
        .attr("role", "alert")
        .text(text);

      if (status === STATUS.INVALID) {
        // Same look as before for real invalid numbers.
        message.addClass("validation_message vat-error").css("color", "red");
      } else {
        // A temporary VIES problem must not look like an invalid number.
        message.addClass("vat-notice").css("color", "#b45309");
      }

      container.append(message);
    }

    function fillMappings(input, data) {
      const form = input.closest("form");
      const address = data.address || {};
      const set = (target, value) => {
        if (!target || value === undefined || value === null) {
          return;
        }
        value = String(value).trim();
        if (!value) {
          return;
        }
        form.find(`[name="input_${target}"]`).val(value).trigger("change");
      };

      set(input.data("map-name"), data.name);
      set(
        input.data("map-street"),
        [address.street, address.number].filter(Boolean).join(" "),
      );
      set(input.data("map-zip"), address.zip_code);
      set(input.data("map-city"), address.city);
      set(input.data("map-country"), address.country);
    }

    function render(input, key, result) {
      const state = stateOf(input);
      const container = containerOf(input);

      clearStatus(input);
      state.shownKey = key;
      state.shownStatus = result.status;

      if (result.status === STATUS.VALID) {
        input.addClass("vat-valid");
        input.data("vat-valid", true);
        showIcon(container, "checkmark");
        container.find(".sitesoft-euvat-token").val(result.data.token || "");
        fillMappings(input, result.data);
        return;
      }

      if (result.status === STATUS.INVALID) {
        input.addClass("vat-invalid");
        input.attr("aria-invalid", "true");
        showIcon(container, "invalid");
        showMessage(container, STATUS.INVALID, result.message);
        return;
      }

      input.addClass("vat-temporary-error");
      showIcon(container, "temporary");
      showMessage(
        container,
        STATUS.TEMPORARY,
        result.message || temporaryMessage,
      );
    }

    function parseResponse(response) {
      const data = (response && response.data) || {};

      if (response && response.success === true) {
        return { status: STATUS.VALID, data, message: data.message || "" };
      }

      if (
        response &&
        response.success === false &&
        data.status === STATUS.INVALID
      ) {
        return { status: STATUS.INVALID, data, message: data.message || "" };
      }

      // Anything else (temporary_error, unknown shape, "-1", ...) is technical.
      return {
        status: STATUS.TEMPORARY,
        data,
        message: data.message || temporaryMessage,
      };
    }

    function validate(input) {
      const state = stateOf(input);
      cancelTimer(state);

      const current = read(input);
      const key = current.key;

      if (!key) {
        return;
      }

      // Identical request already running (e.g. debounce fired, then blur).
      if (state.inFlightKey === key) {
        return;
      }

      // Unchanged value with a final answer on screen.
      if (state.shownKey === key && state.shownStatus !== STATUS.TEMPORARY) {
        return;
      }

      if (answers.has(key)) {
        render(input, key, answers.get(key));
        return;
      }

      abortRequest(state);
      const seq = state.seq;
      state.inFlightKey = key;
      input.addClass("vat-checking");

      const isCurrent = () => seq === state.seq && read(input).key === key;

      const finish = (result) => {
        state.xhr = null;
        state.inFlightKey = "";
        input.removeClass("vat-checking");

        if (result.status !== STATUS.TEMPORARY) {
          answers.set(key, result);
        }
        render(input, key, result);
      };

      const xhr = opts.ajax({
        url: settings.ajax_url,
        type: "POST",
        dataType: "json",
        data: {
          action: "validate_vat_number",
          country_code: current.country,
          vat: input.val().trim(),
          nonce: settings.nonce,
        },
      });
      state.xhr = xhr;

      xhr.done((response) => {
        if (!isCurrent()) {
          return;
        }
        finish(parseResponse(response));
      });

      xhr.fail((jqXHR, textStatus) => {
        if (textStatus === "abort" || !isCurrent()) {
          return;
        }
        // Network problem / HTTP error / invalid JSON: never "invalid".
        finish(parseResponse(jqXHR && jqXHR.responseJSON));
      });
    }

    function onValueChange(input, validateWhenIncomplete) {
      const state = stateOf(input);
      const current = read(input);

      // Any real change immediately drops the previous status (and token).
      if (current.key !== state.shownKey) {
        clearStatus(input);
      }

      if (state.inFlightKey && state.inFlightKey !== current.key) {
        abortRequest(state);
        input.removeClass("vat-checking");
      }

      cancelTimer(state);

      if (!current.key) {
        return;
      }

      const pattern = COMPLETE_WHILE_TYPING[current.country];
      if (validateWhenIncomplete || (pattern && pattern.test(current.number))) {
        state.timer = opts.setTimeout(() => {
          state.timer = null;
          validate(input);
        }, opts.debounceMs);
      }
    }

    const $root = $(opts.root);

    $root.on("input.sitesoftEuVat", FIELD, function () {
      onValueChange($(this), false);
    });

    $root.on("blur.sitesoftEuVat", FIELD, function () {
      validate($(this));
    });

    $root.on(
      "change.sitesoftEuVat",
      ".ginput_single_euvat " + COUNTRY,
      function () {
        const input = $(this).closest(".gfield").find(FIELD);
        if (input.length) {
          onValueChange(input, true);
        }
      },
    );

    return {
      validate: (element) => validate($(element)),
      destroy: () => $root.off(".sitesoftEuVat"),
    };
  }

  return { init, normalize, STATUS };
});

if (typeof jQuery !== "undefined" && typeof vatChecker !== "undefined") {
  jQuery(function ($) {
    window.SitesoftEuVat.init($, vatChecker);
  });
}
