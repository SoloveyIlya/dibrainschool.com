(() => {
  const preloader = document.getElementById("preloader");
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const MIN_MS = reduceMotion ? 0 : 1600;
  const MAX_MS = 4000;
  const started = performance.now();
  let finished = false;

  const reveal = () => {
    if (finished) return;
    finished = true;

    const wait = Math.max(0, MIN_MS - (performance.now() - started));

    window.setTimeout(() => {
      document.body.classList.remove("is-loading");
      document.body.classList.add("is-ready");

      if (!preloader) return;
      preloader.classList.add("is-done");
      preloader.setAttribute("aria-hidden", "true");
      const remove = () => preloader.remove();
      preloader.addEventListener("transitionend", remove, { once: true });
      window.setTimeout(remove, 700);
    }, wait);
  };

  const cover = document.querySelector(".video__cover");
  const imageReady = new Promise((resolve) => {
    if (!cover || cover.complete) {
      resolve();
      return;
    }
    cover.addEventListener("load", resolve, { once: true });
    cover.addEventListener("error", resolve, { once: true });
  });

  const fontsReady =
    document.fonts && document.fonts.ready
      ? document.fonts.ready.catch(() => undefined)
      : Promise.resolve();

  Promise.all([fontsReady, imageReady]).then(reveal);
  window.setTimeout(reveal, MAX_MS);

  const PUBLIC_KEY = "https://disk.yandex.ru/i/2nQfUPTicfb8gQ";
  const playerRoot = document.getElementById("vsl");
  const startButton = playerRoot && playerRoot.querySelector(".video__start");
  let sourcePromise = null;
  let startedPlayback = false;

  const resolveSource = () => {
    if (!sourcePromise) {
      sourcePromise = fetch(
        "https://cloud-api.yandex.net/v1/disk/public/resources/download?public_key=" +
          encodeURIComponent(PUBLIC_KEY)
      )
        .then((response) => {
          if (!response.ok) throw new Error("disk");
          return response.json();
        })
        .then((data) => {
          if (!data.href) throw new Error("disk");
          return data.href;
        })
        .catch((error) => {
          sourcePromise = null;
          throw error;
        });
    }

    return sourcePromise;
  };

  const errorEl = playerRoot && playerRoot.querySelector(".video__error");

  const showError = () => {
    if (!playerRoot) return;
    playerRoot.classList.remove("is-buffering");
    playerRoot.classList.add("is-error");
    if (startButton) startButton.hidden = true;
    if (errorEl) errorEl.hidden = false;
  };

  const playFromSource = async () => {
    if (!playerRoot || !startButton || startedPlayback) return;
    startedPlayback = true;
    playerRoot.classList.add("is-buffering");
    playerRoot.classList.remove("is-error");
    startButton.setAttribute("aria-busy", "true");
    startButton.setAttribute("aria-label", "Загрузка видео");

    try {
      const href = await resolveSource();
      const video = playerRoot.querySelector(".video__player");
      if (!video) throw new Error("player");

      video.referrerPolicy = "no-referrer";
      video.preload = "auto";
      video.poster = cover ? cover.currentSrc || cover.src : "";

      const onReady = () => {
        playerRoot.classList.remove("is-buffering");
        playerRoot.classList.add("is-playing");
        startButton.hidden = true;
        startButton.setAttribute("aria-hidden", "true");
        startButton.removeAttribute("aria-busy");
      };

      video.addEventListener("playing", onReady, { once: true });
      video.addEventListener("error", showError, { once: true });
      video.src = href;
      video.load();
      await video.play();
    } catch (error) {
      startedPlayback = false;
      showError();
    }
  };

  if (startButton) {
    startButton.addEventListener("click", playFromSource);
    window.setTimeout(resolveSource, MAX_MS + 200);
  }
})();
