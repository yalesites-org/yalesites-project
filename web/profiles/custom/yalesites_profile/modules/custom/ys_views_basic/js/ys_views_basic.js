Drupal.behaviors.viewsBasic = {
  attach(context) {
    const params = context.querySelector(".views-basic--params");
    const contentType = context.getElementById("edit-content-types");
    const userValues = context.querySelectorAll(".views-basic--user-value");

    function updateUserValues() {
      const paramsParsed = JSON.parse(params.value);
      contentType.value = paramsParsed.contentType;
    }

    function updateParams() {
      const paramsList = {
        contentType: contentType.options[contentType.selectedIndex].value,
      };
      params.value = JSON.stringify(paramsList);
    }

    if (params.value) {
      updateUserValues();
    } else {
      updateParams();
    }

    for (let index = 0; index < userValues.length; index++) {
      const element = userValues[index];
      element.addEventListener("change", () => {
        updateParams();
      });
    }
  },
};
