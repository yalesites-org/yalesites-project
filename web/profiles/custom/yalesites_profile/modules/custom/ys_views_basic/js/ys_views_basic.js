Drupal.behaviors.viewsBasic = {
  attach(context) {
    once("viewsBasicBehavior", ".views-basic--params", context).forEach(
      function (element) {
        const params = context.querySelector(".views-basic--params");
        const userValues = context.querySelectorAll('.views-basic--user-value');
        // const contentType = document.querySelector(
        //   ".views-basic--user-value[data-drupal-selector='edit-content-types']"
        // );
        //const userValues = context.querySelectorAll(".views-basic--user-value");

        //console.log(userValues);

        function updateUserValues() {
          const paramsParsed = JSON.parse(params.value);
          //contentType.value = paramsParsed.contentType;
        }

        function updateParams(param) {
          //console.log(param.target.dataset);
          const paramName = param.dataset.views_basic_param;
          console.log(paramName);
          // const paramsList = {
          //   []:
          //     param.options[param.selectedIndex].value,
          // };
          // params.value = JSON.stringify(paramsList);
        }

        if (params.value) {
          updateUserValues();
        } else {
          updateParams();
        }

        for (let index = 0; index < userValues.length; index++) {
          const userValueElement = userValues[index];
          userValueElement.addEventListener("change", (param) => {
            updateParams(param);
          });
        }
      }
    );
  },
};
