async function buildArchitecture() {
  const PAGE_NAME = "TE-KG Detailed Architecture";
  const ROOT_NAME = "TE-KG Data Architecture and Public Services";

  const colors = {
    ink: "#08255B",
    blue: "#0B69C7",
    teal: "#078D9D",
    tealDark: "#057786",
    border: "#A8CDED",
    lane: "#F7FBFF",
    white: "#FFFFFF",
    soft: "#FBFDFF",
    deepSeek: "#4D6BFE"
  };

  function rgb(hex) {
    const value = hex.replace("#", "");
    return {
      r: parseInt(value.slice(0, 2), 16) / 255,
      g: parseInt(value.slice(2, 4), 16) / 255,
      b: parseInt(value.slice(4, 6), 16) / 255
    };
  }

  function solid(hex, opacity) {
    const paint = { type: "SOLID", color: rgb(hex) };
    if (opacity !== undefined) paint.opacity = opacity;
    return paint;
  }

  const available = (await figma.listAvailableFontsAsync()).map((entry) => entry.fontName);

  function findFont(family, styleNames) {
    const normalizedStyles = styleNames.map((style) => style.toLowerCase().replace(/\s+/g, ""));
    return available.find((font) =>
      font.family.toLowerCase() === family.toLowerCase() &&
      normalizedStyles.includes(font.style.toLowerCase().replace(/\s+/g, ""))
    );
  }

  function selectFonts() {
    const preferred = {
      regular: findFont("Roboto Condensed", ["Regular"]),
      semibold: findFont("Roboto Condensed", ["SemiBold", "Semi Bold"]),
      bold: findFont("Roboto Condensed", ["Bold"]),
      extrabold: findFont("Roboto Condensed", ["ExtraBold", "Extra Bold"])
    };
    if (preferred.regular && preferred.semibold && preferred.bold) {
      preferred.extrabold = preferred.extrabold || preferred.bold;
      return preferred;
    }

    const fallback = {
      regular: findFont("Inter", ["Regular"]),
      semibold: findFont("Inter", ["Semi Bold", "SemiBold"]),
      bold: findFont("Inter", ["Bold"]),
      extrabold: findFont("Inter", ["Extra Bold", "ExtraBold"])
    };
    fallback.semibold = fallback.semibold || fallback.bold || fallback.regular;
    fallback.bold = fallback.bold || fallback.semibold || fallback.regular;
    fallback.extrabold = fallback.extrabold || fallback.bold;
    if (!fallback.regular) throw new Error("Neither Roboto Condensed nor Inter is available.");
    return fallback;
  }

  const fonts = selectFonts();
  const uniqueFonts = [];
  for (const font of Object.values(fonts)) {
    if (!uniqueFonts.some((item) => item.family === font.family && item.style === font.style)) {
      uniqueFonts.push(font);
    }
  }
  await Promise.all(uniqueFonts.map((font) => figma.loadFontAsync(font)));

  let page = figma.root.children.find((candidate) => candidate.name === PAGE_NAME);
  if (!page) {
    page = figma.createPage();
    page.name = PAGE_NAME;
  }
  await figma.setCurrentPageAsync(page);
  for (const child of [...page.children]) child.remove();

  const root = figma.createFrame();
  root.name = ROOT_NAME;
  root.resize(1536, 1024);
  root.x = 100;
  root.y = 100;
  root.clipsContent = false;
  root.fills = [solid(colors.white)];
  page.appendChild(root);

  function addFrame(parent, name, x, y, width, height, options) {
    const opts = options || {};
    const frame = figma.createFrame();
    frame.name = name;
    frame.x = x;
    frame.y = y;
    frame.resize(width, height);
    frame.clipsContent = false;
    frame.cornerRadius = opts.radius === undefined ? 9 : opts.radius;
    frame.fills = opts.fill === null ? [] : [solid(opts.fill || colors.white)];
    frame.strokes = opts.stroke === null ? [] : [solid(opts.stroke || colors.border)];
    frame.strokeWeight = opts.strokeWeight === undefined ? 1.3 : opts.strokeWeight;
    if (opts.shadow) {
      frame.effects = [{
        type: "DROP_SHADOW",
        color: { ...rgb(colors.ink), a: 0.10 },
        offset: { x: 0, y: 2 },
        radius: 4,
        spread: 0,
        visible: true,
        blendMode: "NORMAL"
      }];
    }
    parent.appendChild(frame);
    return frame;
  }

  function addText(parent, name, characters, x, y, width, size, weight, color, align, lineHeight) {
    const text = figma.createText();
    text.name = name;
    text.fontName = fonts[weight || "regular"];
    text.characters = characters;
    text.fontSize = size;
    text.fills = [solid(color || colors.ink)];
    text.textAlignHorizontal = align || "LEFT";
    text.textAlignVertical = "TOP";
    text.x = x;
    text.y = y;
    text.resize(width, Math.max(size * 1.4, 20));
    text.textAutoResize = "HEIGHT";
    if (lineHeight) text.lineHeight = { unit: "PIXELS", value: lineHeight };
    parent.appendChild(text);
    return text;
  }

  function addEllipse(parent, name, x, y, width, height, fill, stroke, strokeWeight) {
    const ellipse = figma.createEllipse();
    ellipse.name = name;
    ellipse.x = x;
    ellipse.y = y;
    ellipse.resize(width, height);
    ellipse.fills = fill ? [solid(fill)] : [];
    ellipse.strokes = stroke ? [solid(stroke)] : [];
    ellipse.strokeWeight = strokeWeight || 1;
    parent.appendChild(ellipse);
    return ellipse;
  }

  function addRect(parent, name, x, y, width, height, fill, radius) {
    const rect = figma.createRectangle();
    rect.name = name;
    rect.x = x;
    rect.y = y;
    rect.resize(width, height);
    rect.cornerRadius = radius || 0;
    rect.fills = [solid(fill)];
    rect.strokes = [];
    parent.appendChild(rect);
    return rect;
  }

  function addIcon(parent, name, svg, x, y, width, height, color) {
    const icon = figma.createNodeFromSvg(svg.replaceAll("currentColor", color || colors.ink));
    icon.name = name;
    icon.x = x;
    icon.y = y;
    icon.resize(width, height);
    parent.appendChild(icon);
    return icon;
  }

  function addSegment(parent, name, x1, y1, x2, y2, color, thickness, dash) {
    const strokeColor = color || colors.teal;
    const weight = thickness || 2;
    if (dash && y1 === y2) {
      const start = Math.min(x1, x2);
      const length = Math.abs(x2 - x1);
      let last = null;
      for (let offset = 0; offset < length; offset += dash[0] + dash[1]) {
        last = addRect(parent, name + " dash", start + offset, y1 - weight / 2, Math.min(dash[0], length - offset), weight, strokeColor, weight / 2);
      }
      return last;
    }
    if (dash && x1 === x2) {
      const start = Math.min(y1, y2);
      const length = Math.abs(y2 - y1);
      let last = null;
      for (let offset = 0; offset < length; offset += dash[0] + dash[1]) {
        last = addRect(parent, name + " dash", x1 - weight / 2, start + offset, weight, Math.min(dash[0], length - offset), strokeColor, weight / 2);
      }
      return last;
    }
    if (!dash && y1 === y2) {
      return addRect(parent, name, Math.min(x1, x2), y1 - weight / 2, Math.abs(x2 - x1), weight, strokeColor, weight / 2);
    }
    if (!dash && x1 === x2) {
      return addRect(parent, name, x1 - weight / 2, Math.min(y1, y2), weight, Math.abs(y2 - y1), strokeColor, weight / 2);
    }
    const line = figma.createLine();
    line.name = name;
    line.x = x1;
    line.y = y1;
    const dx = x2 - x1;
    const dy = y2 - y1;
    line.resize(Math.sqrt(dx * dx + dy * dy), 0);
    line.rotation = Math.atan2(dy, dx) * 180 / Math.PI;
    line.strokes = [solid(strokeColor)];
    line.strokeWeight = weight;
    line.strokeCap = "ROUND";
    if (dash) line.dashPattern = dash;
    parent.appendChild(line);
    return line;
  }

  const arrowRightSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path fill="#078D9D" d="M0 0L10 5 0 10Z"/></svg>';
  const arrowLeftSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path fill="#078D9D" d="M10 0L0 5l10 5Z"/></svg>';
  const arrowDownSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path fill="#078D9D" d="M0 0h10L5 10Z"/></svg>';
  const arrowUpSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path fill="#078D9D" d="M0 10 5 0l5 10Z"/></svg>';

  function arrowRight(parent, x1, y, x2, name) {
    addSegment(parent, name || "Flow", x1, y, x2 - 10, y, colors.teal, 2);
    addIcon(parent, (name || "Flow") + " arrow", arrowRightSvg, x2 - 10, y - 5, 10, 10);
  }

  function arrowLeft(parent, x1, y, x2, name) {
    addSegment(parent, name || "Flow", x1, y, x2 + 10, y, colors.teal, 2);
    addIcon(parent, (name || "Flow") + " arrow", arrowLeftSvg, x2, y - 5, 10, 10);
  }

  function arrowDown(parent, x, y1, y2, name) {
    addSegment(parent, name || "Flow", x, y1, x, y2 - 10, colors.teal, 2);
    addIcon(parent, (name || "Flow") + " arrow", arrowDownSvg, x - 5, y2 - 10, 10, 10);
  }

  function arrowUp(parent, x, y1, y2, name, dashed) {
    addSegment(parent, name || "Verification route", x, y1, x, y2 + 10, colors.teal, 2, dashed ? [7, 6] : undefined);
    addIcon(parent, (name || "Verification route") + " arrow", arrowUpSvg, x - 5, y2, 10, 10);
  }

  const icons = {
    file: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 5h23l12 12v42H16z"/><path d="M39 5v13h12M24 30h19M24 39h19M24 48h13"/></svg>',
    search: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="27" cy="27" r="18"/><path d="M40 40l15 15"/></svg>',
    dna: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 4c25 15 5 41 30 56M47 4C22 19 42 45 17 60"/><path d="M22 12h20M18 23h28M18 41h28M22 52h20" stroke="#078D9D"/></svg>',
    tree: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="24" y="3" width="16" height="13" rx="2"/><rect x="4" y="46" width="16" height="13" rx="2" stroke="#078D9D"/><rect x="44" y="46" width="16" height="13" rx="2" stroke="#078D9D"/><path d="M32 16v13M32 29H12v17M32 29h20v17"/></svg>',
    chart: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path d="M6 57h52" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><rect x="12" y="31" width="9" height="26" rx="1" fill="#0B69C7"/><rect x="28" y="11" width="9" height="46" rx="1" fill="#078D9D"/><rect x="44" y="22" width="9" height="35" rx="1" fill="#0B69C7"/></svg>',
    clipboard: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="13" y="9" width="38" height="50" rx="5"/><path d="M24 9V4h16v5"/><path d="M22 27l6 6 13-14M22 45h20" stroke="#078D9D"/></svg>',
    network: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path d="M13 14l20 12 18-15M13 14l7 35 13-23 18 27M20 49l31 4" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="13" cy="14" r="6" fill="#0B69C7" stroke="#08255B" stroke-width="2"/><circle cx="33" cy="26" r="6" fill="#FFFFFF" stroke="#08255B" stroke-width="2"/><circle cx="51" cy="11" r="6" fill="#078D9D" stroke="#08255B" stroke-width="2"/><circle cx="20" cy="49" r="6" fill="#0B69C7" stroke="#08255B" stroke-width="2"/><circle cx="51" cy="53" r="6" fill="#0B69C7" stroke="#08255B" stroke-width="2"/></svg>',
    database: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="32" cy="13" rx="23" ry="9"/><path d="M9 13v18c0 5 10 9 23 9s23-4 23-9V13M9 31v18c0 5 10 9 23 9s23-4 23-9V31"/></svg>',
    browse: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2"><circle cx="27" cy="27" r="21"/><path d="M42 42l16 16" stroke-linecap="round"/><circle cx="18" cy="19" r="2.6" fill="#078D9D" stroke="none"/><circle cx="18" cy="28" r="2.6" fill="#078D9D" stroke="none"/><circle cx="18" cy="37" r="2.6" fill="#078D9D" stroke="none"/><path d="M26 19h13M26 28h13M26 37h10" stroke="#078D9D" stroke-linecap="round"/></svg>',
    path: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path d="M8 50 24 34l13-14 19-8" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="8" cy="50" r="5" fill="#0B69C7" stroke="#08255B" stroke-width="2"/><circle cx="24" cy="34" r="5" fill="#FFFFFF" stroke="#078D9D" stroke-width="2"/><circle cx="37" cy="20" r="5" fill="#FFFFFF" stroke="#078D9D" stroke-width="2"/><circle cx="56" cy="12" r="5" fill="#0B69C7" stroke="#08255B" stroke-width="2"/></svg>',
    bot: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="12" y="16" width="40" height="35" rx="10"/><path d="M32 7v9M27 7h10M12 29H5v12h7M52 29h7v12h-7M23 52v6M41 52v6"/><circle cx="25" cy="31" r="3" fill="#078D9D" stroke="none"/><circle cx="39" cy="31" r="3" fill="#0B69C7" stroke="none"/><path d="M24 42h16"/></svg>',
    deepSeek: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#4D6BFE" d="M23.748 4.651c-.254-.124-.364.113-.512.233-.051.04-.094.09-.137.137-.372.397-.806.657-1.373.626-.829-.046-1.537.214-2.163.848-.133-.782-.575-1.248-1.247-1.548-.352-.155-.708-.311-.955-.65-.172-.24-.219-.509-.305-.774-.055-.16-.11-.323-.293-.35-.2-.031-.278.136-.356.276-.313.572-.434 1.202-.422 1.84.027 1.436.633 2.58 1.838 3.393.137.094.172.187.129.323-.082.28-.18.553-.266.833-.055.179-.137.218-.328.14a5.5 5.5 0 0 1-1.737-1.179c-.857-.828-1.631-1.743-2.597-2.46a12 12 0 0 0-.689-.47c-.985-.957.13-1.743.387-1.836.27-.098.094-.433-.778-.428-.872.003-1.67.295-2.687.685a3 3 0 0 1-.465.136 9.6 9.6 0 0 0-2.883-.101c-1.885.21-3.39 1.1-4.497 2.622C.082 8.776-.231 10.854.152 13.02c.403 2.284 1.568 4.175 3.36 5.653 1.857 1.533 3.997 2.284 6.438 2.14 1.482-.085 3.132-.284 4.994-1.86.47.234.962.328 1.78.398.629.058 1.235-.031 1.705-.129.735-.155.684-.836.418-.961-2.155-1.004-1.682-.595-2.112-.926 1.095-1.295 2.768-3.598 3.284-6.733.05-.346.115-.834.108-1.114-.004-.171.035-.238.23-.257a4.2 4.2 0 0 0 1.545-.475c1.397-.763 1.96-2.016 2.093-3.517.02-.23-.004-.467-.247-.588M11.58 18.168c-2.088-1.642-3.101-2.183-3.52-2.16-.39.024-.32.472-.234.763.09.288.207.487.371.74.114.167.192.416-.113.603-.673.416-1.842-.14-1.897-.168-1.361-.801-2.5-1.86-3.301-3.306-.775-1.393-1.225-2.888-1.299-4.482-.02-.385.094-.522.477-.592a4.7 4.7 0 0 1 1.53-.038c2.131.311 3.946 1.264 5.467 2.774.868.86 1.525 1.887 2.202 2.89.72 1.066 1.494 2.082 2.48 2.915.348.291.626.513.892.677-.802.09-2.14.109-3.055-.615zm1.001-6.44a.306.306 0 0 1 .415-.287.3.3 0 0 1 .113.074.3.3 0 0 1 .086.214c0 .17-.136.307-.308.307a.303.303 0 0 1-.306-.307m3.11 1.596c-.2.081-.4.151-.591.16a1.25 1.25 0 0 1-.798-.254c-.274-.23-.47-.358-.551-.758a1.7 1.7 0 0 1 .015-.588c.07-.327-.007-.537-.238-.727-.188-.156-.426-.199-.689-.199a.6.6 0 0 1-.254-.078.253.253 0 0 1-.114-.358 1 1 0 0 1 .192-.21c.356-.202.767-.136 1.146.016.352.144.618.408 1.001.782.392.451.462.576.685.915.176.264.336.536.446.848.066.194-.02.353-.25.45"/></svg>'
  };

  // Title and section shells.
  addText(root, "Title", "TE-KG Data Architecture and Public Services", 320, 10, 896, 36, "extrabold", colors.ink, "CENTER", 43);
  addText(root, "Subtitle", "Integrated evidence, taxonomy, genomic annotation, expression and co-expression for human transposable elements", 250, 53, 1036, 19, "regular", colors.ink, "CENTER", 24);

  const sectionSpecs = [
    { number: "1", title: "DATA COLLECTION", y: 82, height: 238 },
    { number: "2", title: "PROCESSING & INTEGRATION", y: 332, height: 340 },
    { number: "3", title: "INFORMATION & SERVICES", y: 686, height: 294 }
  ];
  for (const spec of sectionSpecs) {
    addFrame(root, "Section " + spec.number, 24, spec.y, 1488, spec.height, {
      fill: colors.soft,
      stroke: colors.blue,
      strokeWeight: 1.8,
      radius: 10
    });
    addEllipse(root, "Section " + spec.number + " badge", 40, spec.y + 10, 42, 42, colors.ink);
    addText(root, "Section " + spec.number + " number", spec.number, 40, spec.y + 14, 42, 26, "extrabold", colors.white, "CENTER", 32);
    addText(root, "Section " + spec.number + " title", spec.title, 92, spec.y + 12, 600, 29, "extrabold", colors.ink, "LEFT", 35);
  }

  // Data source arrows sit behind the cards and terminate at the processing section.
  [226, 564, 917, 1285].forEach((x, index) => arrowDown(root, x, 302, 332, "Source entry " + (index + 1)));

  function sourceCard(name, x, width) {
    return addFrame(root, name, x, 124, width, 178, {
      fill: colors.white,
      stroke: colors.border,
      strokeWeight: 1.4,
      radius: 11,
      shadow: true
    });
  }

  const pubmed = sourceCard("Source - PubMed Literature", 76, 300);
  addIcon(pubmed, "Literature document", icons.file, 76, 14, 74, 74);
  addIcon(pubmed, "Literature search", icons.search, 127, 55, 39, 39, colors.teal);
  addText(pubmed, "PubMed Literature", "PubMed Literature", 20, 98, 260, 25, "bold", colors.ink, "CENTER", 30);
  addText(pubmed, "PubMed count", "2,308 curated papers", 20, 132, 260, 18, "regular", colors.ink, "CENTER", 24);

  const rmsk = sourceCard("Source - RMSK", 399, 330);
  addIcon(rmsk, "RMSK DNA", icons.dna, 126, 10, 78, 78);
  addSegment(rmsk, "Genomic coordinate axis", 72, 88, 258, 88, colors.ink, 1.6);
  for (let i = 0; i < 5; i += 1) addSegment(rmsk, "Coordinate tick " + (i + 1), 84 + i * 40, 83, 84 + i * 40, 93, colors.ink, 1.3);
  addEllipse(rmsk, "Genomic location marker", 151, 72, 28, 28, colors.teal, colors.ink, 1.5);
  addEllipse(rmsk, "Genomic location marker core", 160, 81, 10, 10, colors.white);
  addText(rmsk, "RMSK", "RMSK", 20, 103, 290, 25, "bold", colors.ink, "CENTER", 30);
  addText(rmsk, "RMSK description", "Genomic locations", 20, 136, 290, 18, "regular", colors.ink, "CENTER", 24);

  const repbase = sourceCard("Source - RepBase", 752, 330);
  addIcon(repbase, "RepBase document", icons.file, 42, 16, 62, 62);
  addIcon(repbase, "RepBase taxonomy", icons.tree, 134, 16, 62, 62);
  addIcon(repbase, "RepBase DNA", icons.dna, 226, 16, 62, 62);
  addText(repbase, "RepBase", "RepBase", 20, 92, 290, 25, "bold", colors.ink, "CENTER", 30);
  addText(repbase, "RepBase description", "TE classification &\nreference sequences", 20, 125, 290, 18, "regular", colors.ink, "CENTER", 22);

  const expressionSource = sourceCard("Source - Expression datasets", 1105, 360);
  addIcon(expressionSource, "Expression datasets", icons.chart, 132, 7, 96, 78);
  addText(expressionSource, "Expression datasets title", "Expression datasets", 20, 84, 320, 24, "bold", colors.ink, "CENTER", 29);
  const sourceBullets = [
    [116, "Normal tissue: E-MTAB-1733, E-MTAB-2836"],
    [138, "Normal cell line: SRP013565"],
    [160, "Cancer cell line: PRJNA523380"]
  ];
  for (const [y, label] of sourceBullets) {
    addEllipse(expressionSource, label + " bullet", 28, y + 2, 7, 7, colors.teal);
    addText(expressionSource, label, label, 43, y - 5, 300, 15, "regular", colors.ink, "LEFT", 20);
  }

  // Processing swimlanes.
  function swimlane(id, y, title, icon) {
    const lane = addFrame(root, "Lane " + id, 76, y, 940, 85, {
      fill: colors.lane,
      stroke: colors.border,
      strokeWeight: 1.2,
      radius: 9
    });
    addSegment(lane, "Lane label divider", 230, 0, 230, 85, colors.border, 1.2);
    addEllipse(lane, "Lane " + id + " badge", 12, 24, 36, 36, colors.teal);
    addText(lane, "Lane " + id + " badge text", id, 12, 28, 36, 23, "extrabold", colors.white, "CENTER", 28);
    addIcon(lane, "Lane " + id + " icon", icon, 56, 17, 52, 52);
    const denseLabel = id === "B";
    addText(lane, "Lane " + id + " label", title, 116, denseLabel ? 11 : 20, 108, denseLabel ? 17 : 19, "bold", colors.ink, "LEFT", denseLabel ? 20 : 22);
    return lane;
  }

  swimlane("A", 380, "Literature\nevidence", icons.file);
  swimlane("B", 475, "Taxonomy\nsequence\n& genome", icons.dna);
  swimlane("C", 570, "Expression &\nco-expression", icons.chart);

  // Flow connectors are created before the step cards so they remain behind content.
  arrowRight(root, 528, 423, 565, "Literature flow 1");
  arrowRight(root, 775, 423, 809, "Literature flow 2");
  arrowRight(root, 540, 518, 590, "Taxonomy flow");
  arrowRight(root, 540, 613, 590, "Expression flow");
  addSegment(root, "Literature convergence", 1017, 423, 1068, 423, colors.teal, 2);
  addSegment(root, "Taxonomy convergence", 860, 518, 1068, 518, colors.teal, 2);
  addSegment(root, "Expression convergence", 860, 613, 1068, 613, colors.teal, 2);
  addSegment(root, "Convergence spine", 1068, 423, 1068, 613, colors.teal, 2);
  arrowRight(root, 1068, 518, 1125, "Integrated resource input");

  function stepCard(name, x, y, width, icon, title, subtitle, iconColor) {
    const card = addFrame(root, name, x, y, width, 72, {
      fill: colors.white,
      stroke: colors.border,
      strokeWeight: 1.2,
      radius: 8
    });
    addIcon(card, name + " icon", icon, 12, 12, 48, 48, iconColor || colors.ink);
    addText(card, name + " title", title, 70, 10, width - 80, 18, "semibold", colors.ink, "LEFT", 21);
    addText(card, name + " subtitle", subtitle, 70, 34, width - 80, 16, "regular", colors.ink, "LEFT", 19);
    return card;
  }

  const literatureStep = stepCard("Literature screening", 320, 387, 208, icons.file, "Literature", "screening");
  addIcon(literatureStep, "Literature screening search", icons.search, 37, 37, 28, 28, colors.teal);

  const deepSeekStep = addFrame(root, "DeepSeek-V3 entity and relation extraction", 565, 387, 210, 72, {
    fill: colors.white,
    stroke: colors.border,
    strokeWeight: 1.2,
    radius: 8
  });
  addIcon(deepSeekStep, "DeepSeek official logo", icons.deepSeek, 13, 12, 49, 49);
  addText(deepSeekStep, "DeepSeek-V3", "DeepSeek-V3", 72, 9, 126, 18, "semibold", colors.ink, "LEFT", 21);
  addText(deepSeekStep, "DeepSeek extraction", "entity & relation\nextraction", 72, 31, 126, 16, "regular", colors.ink, "LEFT", 18);

  stepCard("Normalization and manual curation", 809, 387, 208, icons.clipboard, "Normalization &", "manual curation", colors.teal);
  stepCard("RMSK genomic annotation", 320, 482, 220, icons.dna, "RMSK genomic", "annotation");

  const repbaseStep = addFrame(root, "RepBase taxonomy and sequence integration", 590, 482, 270, 72, {
    fill: colors.white,
    stroke: colors.border,
    strokeWeight: 1.2,
    radius: 8
  });
  addIcon(repbaseStep, "RepBase file", icons.file, 12, 12, 45, 45);
  addIcon(repbaseStep, "RepBase taxonomy", icons.tree, 60, 12, 45, 45, colors.teal);
  addText(repbaseStep, "RepBase integration title", "RepBase taxonomy", 116, 9, 145, 18, "semibold", colors.ink, "LEFT", 21);
  addText(repbaseStep, "RepBase integration subtitle", "& sequence integration", 116, 34, 145, 16, "regular", colors.ink, "LEFT", 19);

  stepCard("Expression processing", 320, 577, 220, icons.chart, "Expression", "processing");
  stepCard("TE-Gene co-expression analysis", 590, 577, 270, icons.network, "TE-Gene", "co-expression analysis");

  const store = addFrame(root, "Integrated runtime stores", 1125, 408, 335, 230, {
    fill: colors.white,
    stroke: colors.blue,
    strokeWeight: 1.8,
    radius: 10,
    shadow: true
  });
  addText(store, "Integrated resource title 1", "Integrated TE Knowledge,", 20, 16, 295, 20, "bold", colors.ink, "CENTER", 24);
  addText(store, "Integrated resource title 2", "Genomic & Expression Resource", 20, 43, 295, 20, "bold", colors.ink, "CENTER", 24);
  addSegment(store, "Store divider", 167, 84, 167, 208, colors.border, 1.2, [5, 5]);
  addIcon(store, "Neo4j store", icons.database, 44, 82, 76, 76);
  addText(store, "Neo4j", "Neo4j", 20, 157, 147, 25, "bold", colors.ink, "CENTER", 30);
  addText(store, "Neo4j description", "Knowledge graph\n& taxonomy", 20, 188, 147, 15, "regular", colors.ink, "CENTER", 18);
  addIcon(store, "MySQL store", icons.database, 213, 82, 76, 76, colors.teal);
  addText(store, "MySQL", "MySQL", 168, 157, 147, 25, "bold", colors.ink, "CENTER", 30);
  addText(store, "MySQL description", "Catalog, expression\n& co-expression", 168, 188, 147, 15, "regular", colors.ink, "CENTER", 18);

  // Runtime stores feed the integrated service bus.
  addSegment(root, "Runtime to service route 1", 1292, 638, 1292, 674, colors.teal, 2);
  addSegment(root, "Runtime to service route 2", 1292, 674, 918, 674, colors.teal, 2);
  arrowDown(root, 918, 674, 696, "Runtime to API service bus");

  // Service bus and public service connectors.
  addFrame(root, "Integrated APIs and evidence services", 553, 696, 430, 38, {
    fill: colors.white,
    stroke: colors.blue,
    strokeWeight: 1.6,
    radius: 7
  });
  addText(root, "Integrated APIs label", "Integrated APIs & evidence services", 553, 702, 430, 24, "bold", colors.ink, "CENTER", 29);

  const serviceCenters = [318, 618, 918, 1218];
  addSegment(root, "Service distribution", serviceCenters[0], 738, serviceCenters[3], 738, colors.teal, 2);
  serviceCenters.forEach((x, index) => arrowDown(root, x, 738, 748, "Service output " + (index + 1)));
  addSegment(root, "Service bus stem", 768, 734, 768, 738, colors.teal, 2);

  // Direct Agent access passes through the gap between Path and Graph.
  arrowDown(root, 768, 734, 875, "Agent service input");

  // Verification routes originate at the answer surface and point back to the public pages.
  addSegment(root, "Verification trunk", serviceCenters[0], 859, serviceCenters[3], 859, colors.teal, 2, [7, 6]);
  addSegment(root, "Verification origin", 690, 875, 690, 859, colors.teal, 2, [7, 6]);
  serviceCenters.forEach((x, index) => arrowUp(root, x, 859, 844, "Verification route " + (index + 1), true));

  function serviceCard(name, x, width, icon, title, lines) {
    const card = addFrame(root, name, x, 748, width, 96, {
      fill: colors.white,
      stroke: colors.border,
      strokeWeight: 1.2,
      radius: 9
    });
    addIcon(card, name + " icon", icon, 14, 15, 62, 62);
    addText(card, name + " title", title, 91, 14, width - 103, 24, "bold", colors.ink, "LEFT", 29);
    const compact = lines.split("\n").length > 2;
    addText(card, name + " description", lines, 91, compact ? 40 : 44, width - 103, compact ? 14 : 15, "regular", colors.ink, "LEFT", compact ? 17 : 19);
    return card;
  }

  serviceCard("Browse service", 198, 240, icons.browse, "Browse", "TE catalog,\ntaxonomy &\nannotations");
  serviceCard("Path service", 498, 240, icons.path, "Path", "Evidence-supported\nconnections");
  serviceCard("Graph service", 798, 240, icons.network, "Graph", "Knowledge graph,\nclassification\n& co-expression");
  serviceCard("Expression service", 1098, 240, icons.chart, "Expression", "Context-specific\nprofiles");

  const agent = addFrame(root, "Agent and DeepThink", 568, 875, 400, 82, {
    fill: colors.white,
    stroke: colors.blue,
    strokeWeight: 1.8,
    radius: 10,
    shadow: true
  });
  addIcon(agent, "Agent and DeepThink icon", icons.bot, 16, 11, 60, 60);
  addText(agent, "Agent and DeepThink title", "Agent & DeepThink", 94, 18, 286, 25, "bold", colors.ink, "LEFT", 30);
  addText(agent, "Agent and DeepThink description", "Evidence-bounded answers", 94, 49, 286, 18, "regular", colors.ink, "LEFT", 23);
  const verificationLabel = addText(root, "Verification routes label", "Verification routes", 350, 868, 200, 16, "semibold", colors.tealDark, "LEFT", 21);
  verificationLabel.fontName = fonts.semibold;

  addText(root, "Evidence note", "Associations, graph paths, expression and co-expression represent distinct evidence types.", 350, 992, 836, 15, "regular", colors.ink, "CENTER", 20);

  root.setRelaunchData({ rebuild: "Rebuild TE-KG architecture" });
  figma.currentPage.selection = [root];
  figma.viewport.scrollAndZoomIntoView([root]);
  figma.closePlugin("TE-KG detailed architecture created.");
}

buildArchitecture().catch((error) => {
  const message = error && error.message ? error.message : String(error);
  figma.closePlugin("TE-KG architecture build failed: " + message);
});
