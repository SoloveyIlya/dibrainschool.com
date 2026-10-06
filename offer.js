(() => {
  const links = document.querySelectorAll(".site-foot a[href]");
  if (!links.length) return;

  const dialog = document.createElement("dialog");
  dialog.className = "offer-dialog";
  dialog.setAttribute("aria-label", "Публичная оферта");
  dialog.innerHTML =
    '<div class="offer-dialog__sheet">' +
    '<button class="offer-dialog__close" type="button">закрыть</button>' +
    '<p class="offer-dialog__status">Открываем оферту</p>' +
    '<div class="offer-doc" hidden></div>' +
    "</div>";
  document.body.appendChild(dialog);

  const status = dialog.querySelector(".offer-dialog__status");
  const doc = dialog.querySelector(".offer-doc");
  const closeButton = dialog.querySelector(".offer-dialog__close");
  let loading = null;

  const showOffer = () => {
    if (loading) return loading;
    const source = links[0].href;
    loading = fetch(source)
      .then((response) => {
        if (!response.ok) throw new Error("status");
        return response.text();
      })
      .then((html) => {
        const parsed = new DOMParser().parseFromString(html, "text/html");
        const article = parsed.querySelector("#offer-doc");
        if (!article) throw new Error("empty");
        doc.innerHTML = article.innerHTML;
        doc.hidden = false;
        status.hidden = true;
        const title = doc.querySelector("h1");
        if (title) {
          title.id = "offer-title";
          dialog.setAttribute("aria-labelledby", "offer-title");
        }
      })
      .catch(() => {
        loading = null;
        window.location.href = source;
      });
    return loading;
  };

  closeButton.addEventListener("click", () => dialog.close());

  links.forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      if (typeof dialog.showModal === "function") {
        if (!dialog.open) dialog.showModal();
        closeButton.focus();
        showOffer();
        return;
      }
      window.location.href = link.href;
    });
  });
})();
