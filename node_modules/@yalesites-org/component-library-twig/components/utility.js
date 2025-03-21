export default (args) => {
  return Object.entries(args).reduce((acc, [key, value]) => {
    acc[key] = value.defaultValue;
    return acc;
  }, {});
};
