jQuery(document).ready(function ($) {
  const $euVatField = ".sitesoft-euvat-field";

  $("body").on("input", $euVatField, function () {
    const input = $(this);
    const container = input.closest(".gfield");
    $(container).find(".icon-wrapper").css("display", "none");
  });

  $("body").on("blur", $euVatField, function () {
    const input = $(this);
    const vat = input.val().trim();
    const container = input.closest(".gfield");

    container.find(".vat-error").remove();

    if (!vat) return;

    $.post(
      vatChecker.ajax_url,
      {
        action: "validate_vat_number",
        vat: vat,
        nonce: vatChecker.nonce,
      },
      function (response) {
        if (response.success) {
          if (!response.hasOwnProperty("data")) {
            return;
          }

          const { address, countryCode, message, name, strAddress, vatNumber } =
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
            form.find(`[name="${mappings.name}"]`).val(name);
          }
          if (mappings.street && address) {
            const street = address.street + " " + (address.number ?? "");
            form.find(`[name="${mappings.street}"]`).val(street);
          }
          console.log(address);
          if (mappings.zip && address?.zip_code) {
            form.find(`[name="${mappings.zip}"]`).val(address.zip_code);
          }
          if (mappings.city && address?.city) {
            form.find(`[name="${mappings.city}"]`).val(address.city);
          }
          if (mappings.country && address?.country) {
            form.find(`[name="${mappings.country}"]`).val(address.country);
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
  });

  // $("form").on("submit", function (e) {
  //   const vatFields = $(this).find("input.gf-vat-field");
  //   let allValid = true;
  //
  //   vatFields.each(function () {
  //     const valid = $(this).data("vat-valid");
  //     if (valid === false || typeof valid === "undefined") {
  //       allValid = false;
  //       $(this).trigger("blur");
  //     }
  //   });
  //
  //   if (!allValid) {
  //     e.preventDefault();
  //   }
  // });
});
