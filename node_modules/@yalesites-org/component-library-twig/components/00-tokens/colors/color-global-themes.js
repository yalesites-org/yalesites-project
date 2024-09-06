// Function to take an object and push each key into a new array.
// return the array as `label` : `key` to use in storybook options.
const getGlobalThemes = (globalThemeTokens) => {
  const globalThemeOptions = {};
  const tempArr = Object.keys(globalThemeTokens);

  tempArr.forEach((element) => {
    globalThemeOptions[globalThemeTokens[element].label] = element;
  });

  return globalThemeOptions;
};

export default getGlobalThemes;
