// A test-only module the probe host imports from another origin than the page's, to show what the
// page's Content-Security-Policy lets an import() of a module from elsewhere do (D6).

declare global {
  interface Window {
    cmsCrossOriginRan?: boolean;
  }
}

window.cmsCrossOriginRan = true;

export const ran = true;
