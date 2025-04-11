jQuery(document).ready(function ($) {
  const $euVatField = ".sitesoft-euvat-field";

  $("body").on("input", $euVatField, function () {
    const input = $(this);
    const container = input.closest(".gfield");
    $(container).find(".icon-wrapper").css("display", "none");
    const $countryCode = $(container).find("select[name='country_code']").val();

    if ($countryCode === "BE" && input.val().length >= 10) {
      handleVatChecker.call(this);
    }
  });

  $("body").on("blur", $euVatField, handleVatChecker);

  function handleVatChecker() {
    const input = $(this);
    const vat = input.val().trim();
    const container = input.closest(".gfield");
    const country_code = $(container).find("select[name='country_code']").val();

    container.find(".vat-error").remove();

    if (!vat) return;

    $.post(
      vatChecker.ajax_url,
      {
        action: "validate_vat_number",
        country_code: country_code,
        vat: vat,
        nonce: vatChecker.nonce,
      },
      function (response) {
        if (response.success) {
          if (!response.hasOwnProperty("data")) {
            return;
          }

          const { address, countryCode, message, name, vatNumber } =
            response.data;
          const vatField = $(".sitesoft-euvat-field");
          const form = vatField.closest("form");
          input.addClass("vat-valid");
          input.data("vat-valid", true);
          $(container)
            .find(".icon-wrapper")
            .css("display", "block")
            .find(".invalid")
            .css("display", "none");
          $(container)
            .find(".icon-wrapper")
            .css("display", "block")
            .find(".checkmark")
            .css("display", "block");

          const mappings = {
            name: vatField.data("map-name"),
            street: vatField.data("map-street"),
            zip: vatField.data("map-zip"),
            city: vatField.data("map-city"),
            country: vatField.data("map-country"),
          };

          if (mappings.name && name) {
            form.find(`[name="input_${mappings.name}"]`).val(name);
          }
          if (mappings.street && address) {
            const street = address.street + " " + (address.number ?? "");
            form.find(`[name="input_${mappings.street}"]`).val(street);
          }
          if (mappings.zip && address?.zip_code) {
            form.find(`[name="input_${mappings.zip}"]`).val(address.zip_code);
          }
          if (mappings.city && address?.city) {
            form.find(`[name="input_${mappings.city}"]`).val(address.city);
          }
          if (mappings.country && address?.country) {
            form
              .find(`[name="input_${mappings.country}"]`)
              .val(address.country);
          }
        } else {
          input.addClass("vat-invalid");
          $(container).find(".checkmark").css("display", "none");
          $(container)
            .find(".icon-wrapper")
            .css("display", "block")
            .find(".invalid")
            .css("display", "block");

          container.append(
            '<div class="gfield_description validation_message vat-error" style="color: red;">' +
              response.data.message +
              "</div>",
          );
        }
      },
    );
  }
});
