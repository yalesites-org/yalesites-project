Drupal.behaviors.viewsBasic = {
  attach(context) {
    once("viewsBasicBehavior", ".views-basic--params", context).forEach(
      function (element) {
        const params = context.querySelector(".views-basic--params");
        const userValues = context.querySelectorAll(".views-basic--user-value");

        function updateUserValues() {
          const paramsParsed = JSON.parse(params.value);
          const paramName = Object.keys(paramsParsed);
          paramName.forEach((parameterName, paramValue) => {
            for (let index = 0; index < userValues.length; index++) {
              const userValueElement = userValues[index];
              if (
                userValueElement.dataset.views_basic_param === parameterName
              ) {
                userValueElement.value = paramsParsed[parameterName];
              }
            }
          });
        }

        function updateParams() {
          const paramsList = {};
          for (let index = 0; index < userValues.length; index++) {
            const userValueElement = userValues[index];
            const paramName = userValueElement.dataset.views_basic_param;
            const paramValue =
              userValueElement.options[userValueElement.selectedIndex].value;
            paramsList[paramName] = paramValue;
          }
          params.value = JSON.stringify(paramsList);
        }

        if (params.value) {
          updateUserValues();
        } else {
          updateParams();
        }

        for (let index = 0; index < userValues.length; index++) {
          const userValueElement = userValues[index];
          userValueElement.addEventListener("change", () => {
            updateParams();
          });
        }
      }
    );
  },
};
