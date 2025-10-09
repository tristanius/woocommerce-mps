jQuery(document).ready(function ($) {
  const mpsActions = [
    "mps_create_products",
    "mps_sync_products",
    "mps_get_token",
    "mps_create_order",
    "mps_start_create_report_prices",
    "mps_test_create_order",
    "mps_test_create_products",
    "mps_update_categories_percentages",
    "mps_update_uvts",
    "mps_update_iva",
    "mps_update_min_price",
  ];

  const ajaxRequest = (data) => {
    $.ajax({
      url: mps_ajax.url,
      type: "POST",
      data: data,
      success: function (response) {
        if (response.success) {
          alert(response.data);
          location.reload();
        } else {
          alert("Error success: " + response.data);
          console.log(response);
        }
      },
      error: function (error) {
        alert("Error: " + error.data);
        console.log(error);
      },
    });
  };

  mpsActions.forEach((action) => {
    $("#" + action).on("click", function (e) {
      e.preventDefault();
      $(this).prop("disabled", true);
      let data = {
        action: action,
        nonce: mps_ajax.nonce,
      };

      if ($(this).data("post-id")) {
        data.order_id = $(this).data("post-id");
      }

      if (action === mpsActions[7]) {
        let optionsData = {};

        $("#percentage_category input").each(function () {
          optionsData[$(this).attr("name")] = {
            value: $(this).val(),
            id: $(this).attr("data"),
          };
        });

        data.percentage_category = optionsData;
      }

      if (action === mpsActions[8]) {
        let optionsData = {};

        $("#uvts_data input").each(function () {
          if ($(this).val() != 0) {
            optionsData[$(this).attr("name")] = $(this).val();
          }
        });

        data.uvts = optionsData;
      }

      if (action === mpsActions[9]) {
        let optionsData;

        $("#iva").each(function () {
          if ($(this).val() != 0) {
            optionsData = $(this).val();
          }
        });

        data.iva = optionsData;
      }

      if (action === mpsActions[10]) {
        let optionsData;

        $("#min_price").each(function () {
          if ($(this).val() != 0) {
            optionsData = $(this).val();
          }
        });

        data.mps_min_price = optionsData;
      }

      ajaxRequest(data);
    });
  });
});
