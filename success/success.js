(() => {
  const manager = "m/pZpgojsRNTg0";
  const openedKey = "dibrain-tg-opened";
  const link = document.getElementById("telegram");

  const readLead = () => {
    try {
      return JSON.parse(sessionStorage.getItem("dibrain-quiz-lead") || "null");
    } catch (error) {
      return null;
    }
  };

  const pick = (data, key) => {
    if (!data || typeof data[key] !== "string") return "";
    if (data[key] === "__other__") {
      const other = data[key + "_other"];
      return typeof other === "string" ? other.trim() : "";
    }
    return data[key].trim();
  };

  const message = (data) => {
    const rows = [
      ["Имя", pick(data, "name")],
      ["Телефон", pick(data, "phone")],
      ["Telegram", pick(data, "telegram")],
      ["Город", pick(data, "city")],
      ["Возраст", pick(data, "age")],
      ["Чем занимаюсь", pick(data, "job")],
      ["Опыт в AI", pick(data, "experience")],
      ["Доход сейчас", pick(data, "income")],
      ["Хочу выйти на", pick(data, "target")],
      ["Зачем навык", pick(data, "purpose")],
      ["Идеальный результат", pick(data, "result")],
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

  const url = "https://t.me/" + manager + "?text=" + encodeURIComponent(message(readLead()));
  if (link) link.href = url;

  const timer = document.getElementById("timer");
  const countNum = document.getElementById("count-num");
  const countHint = document.getElementById("count-hint");
  const lead = document.getElementById("success-lead");
  const secondsLabel = (value) => {
    const mod = value % 10;
    const hundred = value % 100;
    if (mod === 1 && hundred !== 11) return "секунду";
    if (mod >= 2 && mod <= 4 && (hundred < 10 || hundred >= 20)) return "секунды";
    return "секунд";
  };

  let opened = false;
  try {
    opened = sessionStorage.getItem(openedKey) === "1";
  } catch (error) {
    opened = false;
  }

  const markOpened = () => {
    try {
      sessionStorage.setItem(openedKey, "1");
    } catch (error) {
      /* ignore */
    }
  };

  if (opened) {
    if (timer) timer.hidden = true;
    if (lead) {
      lead.textContent =
        "Чат с менеджером можно открыть ещё раз. В сообщении уже будут твои ответы — останется нажать «отправить»";
    }
    return;
  }

  let left = 5;
  const render = () => {
    if (countNum) countNum.textContent = String(left);
    if (countHint) countHint.textContent = "откроется через " + left + " " + secondsLabel(left);
  };
  render();

  const countdown = window.setInterval(() => {
    left -= 1;
    if (left <= 0) {
      window.clearInterval(countdown);
      markOpened();
      window.location.href = url;
      return;
    }
    render();
  }, 1000);

  if (link) {
    link.addEventListener("click", () => {
      window.clearInterval(countdown);
      markOpened();
    });
  }
})();
