const path = require("path");

let nextId = 1;

class MockNode {
  constructor(type) {
    this.id = String(nextId++);
    this.type = type;
    this.name = type;
    this.children = [];
    this.parent = null;
    this.width = 0;
    this.height = 0;
    this.x = 0;
    this.y = 0;
  }

  appendChild(node) {
    if (node.parent) {
      node.parent.children = node.parent.children.filter((child) => child !== node);
    }
    node.parent = this;
    this.children.push(node);
  }

  resize(width, height) {
    this.width = width;
    this.height = height;
  }

  remove() {
    if (this.parent) {
      this.parent.children = this.parent.children.filter((child) => child !== this);
    }
  }

  setRelaunchData(data) {
    this.relaunchData = data;
  }
}

class MockPage extends MockNode {
  constructor() {
    super("PAGE");
    this.selection = [];
  }
}

const documentRoot = new MockNode("DOCUMENT");
const initialPage = new MockPage();
initialPage.name = "TE-KG Overview";
documentRoot.appendChild(initialPage);

function createNode(type) {
  return new MockNode(type);
}

global.figma = {
  root: documentRoot,
  currentPage: initialPage,
  viewport: {
    scrollAndZoomIntoView() {}
  },
  async listAvailableFontsAsync() {
    return ["Regular", "SemiBold", "Bold", "ExtraBold"].map((style) => ({
      fontName: { family: "Roboto Condensed", style }
    }));
  },
  async loadFontAsync() {},
  createPage() {
    const page = new MockPage();
    documentRoot.appendChild(page);
    return page;
  },
  async setCurrentPageAsync(page) {
    this.currentPage = page;
  },
  createFrame: () => createNode("FRAME"),
  createText: () => createNode("TEXT"),
  createEllipse: () => createNode("ELLIPSE"),
  createRectangle: () => createNode("RECTANGLE"),
  createLine: () => createNode("LINE"),
  createNodeFromSvg() {
    const frame = createNode("FRAME");
    frame.appendChild(createNode("VECTOR"));
    return frame;
  },
  closePlugin(message) {
    this.closeMessage = message;
  }
};

require(path.join(__dirname, "code.js"));

setTimeout(() => {
  if (!figma.closeMessage || !figma.closeMessage.includes("created")) {
    throw new Error(figma.closeMessage || "Plugin did not close successfully.");
  }

  const page = figma.root.children.find((candidate) => candidate.name === "TE-KG Detailed Architecture");
  if (!page) throw new Error("Detailed architecture page was not created.");

  const root = page.children.find((candidate) => candidate.name === "TE-KG Data Architecture and Public Services");
  if (!root) throw new Error("Architecture root frame was not created.");

  const all = [];
  const visit = (node) => {
    all.push(node);
    node.children.forEach(visit);
  };
  visit(root);

  const requiredNames = [
    "Source - PubMed Literature",
    "DeepSeek official logo",
    "Integrated runtime stores",
    "Graph service",
    "Agent and DeepThink"
  ];
  const names = new Set(all.map((node) => node.name));
  const missing = requiredNames.filter((name) => !names.has(name));
  if (missing.length) throw new Error("Missing generated layers: " + missing.join(", "));

  console.log(JSON.stringify({
    status: "ok",
    page: page.name,
    root: { width: root.width, height: root.height },
    layerCount: all.length,
    missing
  }, null, 2));
}, 20);
