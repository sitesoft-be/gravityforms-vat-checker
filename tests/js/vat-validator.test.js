const { test } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { JSDOM } = require("jsdom");

const JQUERY = fs.readFileSync(
  require.resolve("jquery/dist/jquery.js"),
  "utf8",
);
const SCRIPT = fs.readFileSync(
  path.join(__dirname, "../../assets/js/vat-validator.js"),
  "utf8",
);

const VALID_RESPONSE = {
  success: true,
  data: {
    status: "valid",
    message: "Valid VAT number",
    vatNumber: "0123456749",
    countryCode: "BE",
    name: "NV SITESOFT TEST",
    address: {
      street: "Kerkstraat",
      number: "1",
      zip_code: "9000",
      city: "Gent",
      country: "BE",
    },
    token: "1791000000." + "a".repeat(64),
  },
};
const INVALID_RESPONSE = {
  success: false,
  data: { status: "invalid", message: "Ongeldig btw-nummer" },
};
const TEMPORARY_RESPONSE = {
  success: false,
  data: {
    status: "temporary_error",
    message:
      "De controle van het btw-nummer is momenteel tijdelijk niet beschikbaar. Probeer het over enkele ogenblikken opnieuw.",
  },
};

function setup({ abortable = true } = {}) {
  const dom = new JSDOM(
    `<!DOCTYPE html><body><form id="gform_3">
      <div class="gfield" id="field_3_7">
        <div class="ginput_container ginput_single_euvat">
          <select name="country_code"><option value="BE">BE</option><option value="DE">DE</option></select>
          <input name="input_7" id="input_3_7" type="text" class="sitesoft-euvat-field large" aria-invalid="false"
            data-map-name="8" data-map-street="9.1" data-map-zip="9.5" data-map-city="9.3" data-map-country="10" />
          <input type="hidden" class="sitesoft-euvat-token" name="euvat_token_7" value="" />
          <div class="icon-wrapper" style="display:none">
            <div class="checkmark" style="display:none"></div>
            <div class="invalid" style="display:none"></div>
            <div class="temporary" style="display:none"></div>
          </div>
        </div>
      </div>
      <input name="input_8" /><input name="input_9.1" /><input name="input_9.5" />
      <input name="input_9.3" /><input name="input_10" />
    </form></body>`,
    { runScripts: "outside-only" },
  );
  const { window } = dom;
  window.eval(JQUERY);
  window.eval(SCRIPT);
  const $ = window.jQuery;

  const requests = [];
  const ajax = (request) => {
    const deferred = $.Deferred();
    const xhr = deferred.promise();
    xhr.aborted = false;
    xhr.abort = () => {
      xhr.aborted = true;
      if (abortable) {
        deferred.reject(xhr, "abort");
      }
    };
    requests.push({
      data: request.data,
      xhr,
      respond: (response) => deferred.resolve(response),
      networkError: () => deferred.reject({ status: 0 }, "error"),
    });
    return xhr;
  };

  const timers = new Map();
  let nextTimer = 1;
  const clock = {
    setTimeout: (fn, ms) => {
      const id = nextTimer++;
      timers.set(id, { fn, ms });
      return id;
    },
    clearTimeout: (id) => timers.delete(id),
  };

  window.SitesoftEuVat.init(
    $,
    {
      ajax_url: "/wp-admin/admin-ajax.php",
      nonce: "nonce",
      messages: { temporary_error: "TEMPORARY FALLBACK" },
    },
    { ajax, ...clock },
  );

  const input = $("#input_3_7");
  const container = input.closest(".gfield");
  const fire = (element, type) =>
    element.dispatchEvent(new window.Event(type, { bubbles: true }));

  return {
    window,
    $,
    input,
    container,
    requests,
    timers,
    type(value) {
      input.val(value);
      fire(input[0], "input");
    },
    // Real focus + blur, like a user tabbing in and out of the field.
    blur() {
      input[0].focus();
      input[0].blur();
    },
    selectCountry(code) {
      const select = container.find("select[name='country_code']");
      select.val(code);
      fire(select[0], "change");
    },
    // Let the debounce timer(s) elapse.
    elapse() {
      const pending = [...timers.values()];
      timers.clear();
      pending.forEach((timer) => timer.fn());
    },
    visibleIcon() {
      // Array.from: plain Node array instead of one from the jsdom realm.
      return Array.from(container.find(".icon-wrapper > div"))
        .filter((el) => el.style.display === "block")
        .map((el) => el.className);
    },
    message() {
      return container.find(".vat-message");
    },
  };
}

test("normalize mirrors the PHP rules", () => {
  const { window } = setup();
  const { normalize } = window.SitesoftEuVat;

  assert.equal(normalize("BE", "BE 0123.456.749").key, "BE|0123456749");
  assert.equal(normalize("be", "0123-456-749").key, "BE|0123456749");
  assert.equal(normalize("BE", "0123 456–749").number, "0123456749");
  assert.equal(normalize("GR", "GR094014201").key, "EL|094014201");
  assert.equal(normalize("BE", "   ").key, "");
});

test("typing is debounced (800ms) and sends one request", () => {
  const t = setup();

  for (const value of [
    "0",
    "01",
    "0123",
    "0123456",
    "012345674",
    "0123456749",
  ]) {
    t.type(value);
  }

  assert.equal(t.requests.length, 0, "no request while typing");
  assert.equal(t.timers.size, 1);
  assert.equal([...t.timers.values()][0].ms, 800);

  t.elapse();
  assert.equal(t.requests.length, 1);
  assert.equal(t.requests[0].data.country_code, "BE");
  assert.equal(t.requests[0].data.vat, "0123456749");
});

test("incomplete Belgian numbers do not trigger requests while typing", () => {
  const t = setup();

  // Old behaviour fired at 10 raw characters, e.g. "BE 0123.45".
  t.type("BE 0123.45");
  t.type("0123 456 7");
  t.elapse();
  // Not a complete Belgian number either: wait for blur.
  t.type("01234567490");
  t.type("2123456749");
  t.elapse();

  assert.equal(t.requests.length, 0);
});

test("input followed by blur sends a single request (blur before debounce)", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.elapse();

  assert.equal(t.requests.length, 1);
});

test("input followed by blur sends a single request (request already running)", () => {
  const t = setup();

  t.type("0123456749");
  t.elapse();
  t.blur();
  t.blur();

  assert.equal(t.requests.length, 1);
});

test("duplicate blur events while a request is running are deduplicated", () => {
  const t = setup();

  t.type("0123");
  t.blur();
  t.blur();
  t.blur();

  assert.equal(t.requests.length, 1);
});

test("an unchanged, already validated value is not validated again", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(VALID_RESPONSE);
  t.blur();
  t.type("BE 0123.456.749"); // same number, different notation
  t.elapse();
  t.blur();

  assert.equal(t.requests.length, 1);
  assert.ok(
    t.input.hasClass("vat-valid"),
    "status kept for formatting-only change",
  );
});

test("valid response shows the valid state and fills the mappings", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(VALID_RESPONSE);

  assert.ok(t.input.hasClass("vat-valid"));
  assert.ok(!t.input.hasClass("vat-invalid"));
  assert.equal(t.input.data("vat-valid"), true);
  assert.deepEqual(t.visibleIcon(), ["checkmark"]);
  assert.equal(t.message().length, 0);
  assert.equal(
    t.container.find(".sitesoft-euvat-token").val(),
    VALID_RESPONSE.data.token,
  );

  const form = t.$("form");
  assert.equal(form.find('[name="input_8"]').val(), "NV SITESOFT TEST");
  assert.equal(form.find('[name="input_9.1"]').val(), "Kerkstraat 1");
  assert.equal(form.find('[name="input_9.5"]').val(), "9000");
  assert.equal(form.find('[name="input_9.3"]').val(), "Gent");
  assert.equal(form.find('[name="input_10"]').val(), "BE");
});

test("empty company data from VIES does not overwrite mapped fields", () => {
  const t = setup();
  const form = t.$("form");
  form.find('[name="input_8"]').val("Typed by user");

  t.selectCountry("DE");
  t.type("123456789");
  t.blur();
  t.requests[0].respond({
    success: true,
    data: {
      status: "valid",
      name: "",
      address: { street: "", number: "", country: "DE" },
    },
  });

  assert.equal(form.find('[name="input_8"]').val(), "Typed by user");
  assert.equal(form.find('[name="input_9.1"]').val(), "");
});

test("invalid response shows the invalid state", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(INVALID_RESPONSE);

  assert.ok(t.input.hasClass("vat-invalid"));
  assert.equal(t.input.attr("aria-invalid"), "true");
  assert.deepEqual(t.visibleIcon(), ["invalid"]);
  assert.equal(t.message().text(), "Ongeldig btw-nummer");
  assert.ok(t.message().hasClass("validation_message"));
});

test("temporary error has its own state and is never shown as invalid", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(TEMPORARY_RESPONSE);

  assert.ok(t.input.hasClass("vat-temporary-error"));
  assert.ok(!t.input.hasClass("vat-invalid"));
  assert.ok(!t.input.hasClass("vat-valid"));
  assert.equal(t.input.attr("aria-invalid"), "false");
  assert.deepEqual(t.visibleIcon(), ["temporary"]);
  assert.equal(t.message().text(), TEMPORARY_RESPONSE.data.message);
  assert.ok(!t.message().hasClass("validation_message"));
  assert.ok(t.message().hasClass("vat-notice"));
});

test("network failure is a temporary error, not invalid", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].networkError();

  assert.ok(t.input.hasClass("vat-temporary-error"));
  assert.equal(t.message().text(), "TEMPORARY FALLBACK");
});

test("an unexpected response (e.g. expired nonce '-1') is a temporary error", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(-1);

  assert.ok(t.input.hasClass("vat-temporary-error"));
});

test("a temporary error is retried on the next blur", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(TEMPORARY_RESPONSE);
  t.blur();

  assert.equal(t.requests.length, 2);
});

test("changing a validated number removes the valid state immediately", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(VALID_RESPONSE);
  t.type("012345674");

  assert.ok(!t.input.hasClass("vat-valid"));
  assert.equal(t.input.data("vat-valid"), false);
  assert.deepEqual(t.visibleIcon(), []);
  assert.equal(t.container.find(".sitesoft-euvat-token").val(), "");
});

test("changing an invalid number removes the invalid state and message", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(INVALID_RESPONSE);
  t.type("012345674");

  assert.ok(!t.input.hasClass("vat-invalid"));
  assert.equal(t.input.attr("aria-invalid"), "false");
  assert.equal(t.message().length, 0);
});

test("a new value aborts the running request", () => {
  const t = setup();

  t.type("0123456749");
  t.elapse();
  t.type("0987654321");

  assert.equal(t.requests[0].xhr.aborted, true);

  t.elapse();
  assert.equal(t.requests.length, 2);
  assert.equal(t.requests[1].data.vat, "0987654321");
});

test("an old response arriving after newer input never overwrites it", () => {
  const t = setup({ abortable: false });

  t.type("0123456749");
  t.elapse();
  t.type("0987654321");
  t.elapse();

  // Newer request answers first, older one arrives late.
  t.requests[1].respond(INVALID_RESPONSE);
  t.requests[0].respond(VALID_RESPONSE);

  assert.ok(t.input.hasClass("vat-invalid"));
  assert.ok(!t.input.hasClass("vat-valid"));
  assert.equal(
    t.$('[name="input_8"]').val(),
    "",
    "no mapping from the stale answer",
  );
});

test("an old response arriving while the user is still typing is ignored", () => {
  const t = setup({ abortable: false });

  t.type("0123456749");
  t.elapse();
  t.type("01234567");
  t.requests[0].respond(VALID_RESPONSE);

  assert.ok(!t.input.hasClass("vat-valid"));
  assert.equal(t.container.find(".sitesoft-euvat-token").val(), "");
});

test("going back to an already validated number reuses the answer", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(VALID_RESPONSE);
  t.type("0987654321");
  t.blur();
  t.requests[1].respond(INVALID_RESPONSE);
  t.type("0123456749");
  t.blur();

  assert.equal(t.requests.length, 2);
  assert.ok(t.input.hasClass("vat-valid"));
});

test("changing the country clears the status and re-validates", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond(VALID_RESPONSE);
  t.selectCountry("DE");

  assert.ok(!t.input.hasClass("vat-valid"));
  t.elapse();
  assert.equal(t.requests.length, 2);
  assert.equal(t.requests[1].data.country_code, "DE");
});

test("server messages are rendered as text, not HTML", () => {
  const t = setup();

  t.type("0123456749");
  t.blur();
  t.requests[0].respond({
    success: false,
    data: { status: "invalid", message: "<img src=x onerror=alert(1)>" },
  });

  assert.equal(t.message().find("img").length, 0);
  assert.equal(t.message().text(), "<img src=x onerror=alert(1)>");
});
