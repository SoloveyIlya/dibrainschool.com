(() => {
  const form = document.getElementById("quiz");
  const thanks = document.getElementById("thanks");
  const progress = document.getElementById("progress");
  const progressbar = document.getElementById("progressbar");
  const progressFill = document.getElementById("progress-fill");
  const count = document.getElementById("count");
  const back = document.getElementById("back");
  const next = document.getElementById("next");
  const nextLabel = next.querySelector(".cta__label");
  const formError = document.getElementById("form-error");
  const again = document.getElementById("again");
  const steps = [...form.querySelectorAll(".step")];
  const total = steps.length - 1;
  const storageKey = "dibrain-quiz";
  const doneKey = "dibrain-quiz-done";
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  let advanceTimer = 0;
  let sending = false;

  const readState = () => {
    try {
      return JSON.parse(sessionStorage.getItem(storageKey) || "null");
    } catch (error) {
      return null;
    }
  };

  const writeState = (step) => {
    try {
      const fields = {};
      new FormData(form).forEach((value, key) => {
        if (key === "company" || typeof value !== "string") return;
        fields[key] = value;
      });
      sessionStorage.setItem(storageKey, JSON.stringify({ step: step, fields: fields }));
    } catch (error) {
      return;
    }
  };

  const currentIndex = () => steps.findIndex((step) => !step.hidden);

  const clearError = (step) => {
    step.querySelectorAll(".error").forEach((error) => {
      error.hidden = true;
      error.textContent = "";
    });
    step.querySelectorAll("[aria-invalid]").forEach((field) => field.removeAttribute("aria-invalid"));
  };

  const showError = (step, message, field) => {
    const error = step.querySelector(".error");
    error.hidden = false;
    error.textContent = message;
    if (!field) return false;
    field.setAttribute("aria-invalid", "true");
    field.focus();
    return false;
  };

  const letters = (value) => /\p{L}/u.test(value);

  const normalizeTelegram = (value) => {
    let nick = value.trim();
    nick = nick.replace(/^https?:\/\/(?:t\.me|telegram\.me)\//i, "");
    nick = nick.replace(/^@+/, "");
    nick = nick.split(/[/?#\s]/)[0];
    return nick ? "@" + nick : "";
  };

  const checkValue = (kind, value) => {
    if (kind === "name") {
      if (value.length < 2 || !letters(value)) return "Напиши имя и фамилию";
      return "";
    }
    if (kind === "phone") {
      const digits = value.replace(/\D/g, "");
      if (digits.length < 10 || digits.length > 15) return "Укажи номер телефона";
      return "";
    }
    if (kind === "telegram") {
      if (!/^@[A-Za-z][A-Za-z0-9_]{4,31}$/.test(value)) {
        return "Укажи ник в телеграме, например @username";
      }
      return "";
    }
    if (kind === "city") {
      if (value.length < 2 || !letters(value)) return "Напиши свой город";
      return "";
    }
    if (kind === "result") {
      if (value.length < 10) return "Расскажи чуть подробнее — хотя бы пару слов";
      return "";
    }
    return "";
  };

  const validate = (step) => {
    clearError(step);
    formError.hidden = true;
    if (step.dataset.kind === "contacts") {
      const fields = [...step.querySelectorAll("[data-kind]")];
      let firstInvalid = null;
      fields.forEach((field) => {
        if (field.dataset.kind === "telegram") field.value = normalizeTelegram(field.value);
        const message = checkValue(field.dataset.kind, field.value.trim());
        const error = field.parentElement.querySelector(".error");
        if (!message) return;
        error.hidden = false;
        error.textContent = message;
        field.setAttribute("aria-invalid", "true");
        if (!firstInvalid) firstInvalid = field;
      });
      if (firstInvalid) {
        firstInvalid.focus();
        return false;
      }
      return true;
    }

    if (step.dataset.kind === "choice") {
      const picked = step.querySelector('input[type="radio"]:checked');
      if (!picked) return showError(step, "Выбери вариант", step.querySelector("input"));
      if (picked.value === "__other__") {
        const other = step.querySelector(".other input");
        if (other.value.trim().length < 2) return showError(step, "Напиши свой вариант", other);
      }
      return true;
    }

    const field = step.querySelector("input, textarea");
    if (!field) return true;
    if (step.dataset.kind === "telegram") field.value = normalizeTelegram(field.value);
    const message = checkValue(step.dataset.kind, field.value.trim());
    if (message) return showError(step, message, field);
    return true;
  };

  const show = (index, focus) => {
    window.clearTimeout(advanceTimer);
    steps.forEach((step, stepIndex) => {
      step.hidden = stepIndex !== index;
    });

    const intro = index === 0;
    const last = index === steps.length - 1;
    progress.hidden = intro;
    count.hidden = intro;
    back.hidden = intro;
    nextLabel.textContent = intro ? "начать" : last ? "отправить" : "дальше";

    if (!intro) {
      const question = index;
      progressFill.style.width = (question / total) * 100 + "%";
      progressbar.setAttribute("aria-valuenow", String(question));
      progressbar.setAttribute("aria-label", "Вопрос " + question + " из " + total);
      count.textContent = question + " / " + total;
    }

    writeState(index);

    if (!focus) return;
    const heading = steps[index].querySelector("h1");
    if (!heading) return;
    heading.focus({ preventScroll: true });
    window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
  };

  const goForward = () => {
    if (sending) return;
    const index = currentIndex();
    if (index === 0) {
      show(1, true);
      return;
    }
    if (!validate(steps[index])) return;
    if (index < steps.length - 1) {
      show(index + 1, true);
      return;
    }
    send();
  };

  const payload = () => {
    const data = {};
    new FormData(form).forEach((value, key) => {
      if (typeof value === "string") data[key] = value.trim();
    });
    if (data.telegram) data.telegram = normalizeTelegram(data.telegram);
    return data;
  };

  const send = async () => {
    sending = true;
    next.disabled = true;
    back.disabled = true;
    nextLabel.textContent = "отправляем";
    formError.hidden = true;

    let sent = false;
    try {
      const endpoint = new URL("../quiz.php", window.location.href);
      const response = await fetch(endpoint.href, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(payload()),
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || data.ok !== true) {
        formError.hidden = false;
        formError.textContent =
          data && data.error ? data.error : "Не получилось отправить. Попробуй ещё раз.";
        return;
      }
      sent = true;
      finish();
    } catch (error) {
      formError.hidden = false;
      formError.textContent = "Нет соединения. Проверь интернет и попробуй ещё раз.";
    } finally {
      if (sent) return;
      sending = false;
      next.disabled = false;
      back.disabled = false;
      if (!thanks.hidden) return;
      nextLabel.textContent = "отправить";
    }
  };

  const leadValue = (data, key) => {
    if (!data || typeof data[key] !== "string") return "";
    if (data[key] === "__other__") {
      const other = data[key + "_other"];
      return typeof other === "string" ? other.trim() : "";
    }
    return data[key].trim();
  };

  const telegramMessage = (data) => {
    const rows = [
      ["Имя", leadValue(data, "name")],
      ["Телефон", leadValue(data, "phone")],
      ["Telegram", leadValue(data, "telegram")],
      ["Город", leadValue(data, "city")],
      ["Возраст", leadValue(data, "age")],
      ["Чем занимаюсь", leadValue(data, "job")],
      ["Опыт в AI", leadValue(data, "experience")],
      ["Доход сейчас", leadValue(data, "income")],
      ["Хочу выйти на", leadValue(data, "target")],
      ["Зачем навык", leadValue(data, "purpose")],
      ["Идеальный результат", leadValue(data, "result")],
    ].filter((row) => row[1]);

    const lines = ["Привет! Я заполнил(а) анкету предзаписи в dibrain school."];
    if (!rows.length) {
      lines.push("", "Хочу попасть на разбор.");
      return lines.join("\n");
    }
    lines.push("");
    rows.forEach((row) => {
      lines.push(row[0] + ": " + row[1]);
    });
    return lines.join("\n");
  };

  const finish = () => {
    const lead = payload();
    try {
      sessionStorage.setItem("dibrain-quiz-lead", JSON.stringify(lead));
      sessionStorage.removeItem(storageKey);
      sessionStorage.removeItem(doneKey);
      sessionStorage.removeItem("dibrain-tg-opened");
    } catch (error) {
      /* ignore */
    }
    window.location.href =
      "https://t.me/m/pZpgojsRNTg0?text=" + encodeURIComponent(telegramMessage(lead));
  };

  const restore = () => {
    const state = readState();
    if (!state || !state.fields) return 0;
    Object.entries(state.fields).forEach(([name, value]) => {
      if (typeof value !== "string") return;
      const fields = form.elements[name];
      if (!fields) return;
      if (typeof fields.length === "number" && fields[0] && fields[0].type === "radio") {
        [...fields].forEach((radio) => {
          radio.checked = radio.value === value;
        });
        return;
      }
      if ("value" in fields) fields.value = value;
    });
    steps.forEach((step) => {
      const extra = step.querySelector(".other");
      const picked = step.querySelector('input[type="radio"]:checked');
      if (extra) extra.hidden = !(picked && picked.value === "__other__");
    });
    const step = Number(state.step);
    if (!Number.isInteger(step) || step < 0 || step >= steps.length) return 0;
    return step;
  };

  const restart = () => {
    try {
      sessionStorage.removeItem(storageKey);
      sessionStorage.removeItem(doneKey);
    } catch (error) {
      /* ignore */
    }
    form.reset();
    steps.forEach((step) => {
      const extra = step.querySelector(".other");
      if (extra) extra.hidden = true;
      clearError(step);
    });
    formError.hidden = true;
    thanks.hidden = true;
    form.hidden = false;
    show(0, true);
  };

  form.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" || event.target.tagName !== "INPUT") return;
    const step = event.target.closest(".step");
    if (!step || step.dataset.kind !== "contacts") return;
    const fields = [...step.querySelectorAll("input")];
    const active = fields.indexOf(event.target);
    if (active < 0 || active >= fields.length - 1) return;
    event.preventDefault();
    fields[active + 1].focus();
  });

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    goForward();
  });

  back.addEventListener("click", () => {
    const index = currentIndex();
    if (index > 0) show(index - 1, true);
  });

  form.addEventListener("change", (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== "radio") return;
    const step = input.closest(".step");
    clearError(step);
    const extra = step.querySelector(".other");
    if (extra) {
      const isOther = input.value === "__other__";
      extra.hidden = !isOther;
      if (isOther) {
        const field = extra.querySelector("input");
        if (field) field.focus();
        writeState(currentIndex());
        return;
      }
    }
    writeState(currentIndex());
    const fromIndex = currentIndex();
    if (fromIndex >= steps.length - 1) return;
    window.clearTimeout(advanceTimer);
    advanceTimer = window.setTimeout(() => {
      if (currentIndex() !== fromIndex || sending) return;
      goForward();
    }, 180);
  });

  form.addEventListener("input", (event) => {
    const field = event.target;
    const stack = field.closest(".stack");
    if (stack) {
      const error = stack.querySelector(".error");
      if (error) {
        error.hidden = true;
        error.textContent = "";
      }
      field.removeAttribute("aria-invalid");
    } else {
      const step = field.closest(".step");
      if (step) clearError(step);
    }
    formError.hidden = true;
    writeState(currentIndex());
  });

  again.addEventListener("click", restart);

  try {
    sessionStorage.removeItem(doneKey);
  } catch (error) {
    /* ignore */
  }

  show(restore(), false);
})();
