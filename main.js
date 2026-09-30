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

  const playerRoot = document.getElementById("vsl");
  const startButton = playerRoot && playerRoot.querySelector(".video__start");
  const frame = document.getElementById("vsl-player");
  let player = null;
  let startedPlayback = false;
  let maxWatchSeconds = 0;
  let watchSent = false;

  const track = (event, extra) => {
    const device = window.matchMedia("(max-width: 899px)").matches ? "mobile" : "desktop";
    const agent = navigator.userAgent || "";
    const referrer = document.referrer || "";
    const source =
      /telegram/i.test(agent) || /telegram/i.test(referrer) ? "telegram" : referrer ? "other" : "direct";
    const payload = Object.assign({ event: event, device: device, source: source }, extra || {});
    const body = new Blob([JSON.stringify(payload)], {
      type: "text/plain;charset=UTF-8",
    });

    if (navigator.sendBeacon) {
      navigator.sendBeacon("analytics.php", body);
      return;
    }

    fetch("analytics.php", { method: "POST", body: body, keepalive: true }).catch(() => undefined);
  };

  const flushWatch = () => {
    if (!startedPlayback || watchSent) return;
    const seconds = Math.round(maxWatchSeconds);
    if (seconds < 1) return;
    watchSent = true;
    track("watch", { seconds: seconds });
  };

  const rememberPlayer = (instance) => {
    if (!instance || typeof instance.play !== "function") return;
    player = instance;

    if (instance.Events && typeof instance.on === "function") {
      instance.on(instance.Events.TimeUpdate, (event) => {
        const currentTime = event && event.data ? event.data.currentTime : NaN;
        if (typeof currentTime === "number" && currentTime > maxWatchSeconds) {
          maxWatchSeconds = currentTime;
        }
      });
      instance.on(instance.Events.Ended, flushWatch);
    }

    if (!startedPlayback) return;
    const playback = player.play();
    if (playback && typeof playback.catch === "function") playback.catch(() => undefined);
  };

  const openPlayer = () => {
    if (!playerRoot || !startButton) return;
    playerRoot.classList.add("is-playing");
    startButton.hidden = true;
    startButton.setAttribute("aria-hidden", "true");
  };

  const playFromSource = () => {
    if (!playerRoot || !startButton || startedPlayback) return;
    startedPlayback = true;
    track("play");
    openPlayer();

    if (!player) return;
    const playback = player.play();
    if (playback && typeof playback.catch === "function") playback.catch(() => undefined);
  };

  if (startButton) startButton.addEventListener("click", playFromSource);

  const cta = document.querySelector(".cta");
  if (cta) cta.addEventListener("click", () => track("cta"));

  track("view");

  window.addEventListener("pagehide", flushWatch);
  window.addEventListener("beforeunload", flushWatch);

  if (frame) {
    window.onKinescopeIframeAPIReady = (factory) => {
      if (factory && factory.Events && typeof factory.on === "function") {
        factory.on(factory.Events.Created, (event) => {
          rememberPlayer(event && (event.data || event.target));
        });
      }
    };

    const script = document.createElement("script");
    script.src = "https://player.kinescope.io/latest/iframe.player.js?auto";
    script.async = true;
    document.head.appendChild(script);
  }
})();
