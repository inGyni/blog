document.addEventListener('DOMContentLoaded', (event) => {
  hljs.addPlugin(new CopyButtonPlugin({autohide: false}));
  hljs.highlightAll();
});