document.addEventListener('DOMContentLoaded', (event) => {
  console.log("DOM fully loaded and parsed");
  hljs.addPlugin(new CopyButtonPlugin({autohide: false}));
  hljs.highlightAll();
});