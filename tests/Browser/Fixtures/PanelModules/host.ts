// The test-only probe host of the Browser suite's panel tests (PRD 13.4). The test puts it on a
// real panel page, next to the panel's own script, with the nonce of the page, and gives it what
// to do in the JSON element #cms-probe: the prefix of the addon's modules, a module to import from
// the page's other loopback origin, and the module of React Aria Components to mount. It stands in
// for the panel's addon runtime, which comes later: it loads an addon's modules lazily with
// import(), through the page's import map, renders them with the panel's shared React, and writes
// what happened to window.cmsProbe, which the test reads. A step that fails records its error
// instead of stopping the others.

import * as React from 'react';
import { createElement, type ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

/** The name React gives the object a renderer reads its hooks' dispatcher from. */
const INTERNALS = '__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE';

/** The modules the panel shares, whose exports the host lists. */
const SHARED = ['react', 'react/jsx-runtime', 'react-dom', 'react-dom/client'];

interface ProbeConfig {
  readonly addons: string;
  readonly crossOrigin: string | null;
  readonly kit: string | null;
}

interface Renderer {
  readonly currentDispatcherRef?: unknown;
}

interface ProbeResult {
  done: boolean;
  shared: Record<string, string[]>;
  outsideScope: string;
  addon: string;
  sameReact: boolean;
  renderers: number;
  rendererUsesAddonReact: boolean;
  refused: { name: string; message: string } | null;
  crossOrigin: string;
  kit: string;
}

declare global {
  interface Window {
    cmsProbe?: ProbeResult;
    __REACT_DEVTOOLS_GLOBAL_HOOK__?: { renderers: Map<number, Renderer> };
  }
}

function errorText(error: unknown): string {
  return error instanceof Error ? `${error.name}: ${error.message}` : String(error);
}

function config(): ProbeConfig {
  const text = document.getElementById('cms-probe')?.textContent ?? '';

  return JSON.parse(text) as ProbeConfig;
}

/** A dynamic import of a URL the host computes, which the build leaves as it is. */
function load(url: string): Promise<Record<string, unknown>> {
  return import(/* @vite-ignore */ url) as Promise<Record<string, unknown>>;
}

function mountPoint(id: string): HTMLElement {
  const element = document.createElement('div');
  element.id = id;
  document.body.append(element);

  return element;
}

async function probe(): Promise<void> {
  const settings = config();
  const result: ProbeResult = {
    done: false,
    shared: {},
    outsideScope: '',
    addon: '',
    sameReact: false,
    renderers: 0,
    rendererUsesAddonReact: false,
    refused: null,
    crossOrigin: '',
    kit: '',
  };

  for (const specifier of SHARED) {
    result.shared[specifier] = Object.keys(await load(specifier)).sort();
  }

  try {
    await load('@inertiajs/react');
    result.outsideScope = 'resolved';
  } catch (error) {
    result.outsideScope = `refused ${errorText(error)}`;
  }

  try {
    const counter = await load(`${settings.addons}counter.js`);
    const hostInternals = (React as unknown as Record<string, unknown>)[INTERNALS];
    const renderers = [...(window.__REACT_DEVTOOLS_GLOBAL_HOOK__?.renderers.values() ?? [])];

    result.sameReact = counter.reactInternals === hostInternals;
    result.renderers = renderers.length;
    result.rendererUsesAddonReact = renderers.every(
      (renderer) => renderer.currentDispatcherRef === counter.reactInternals,
    );
    createRoot(mountPoint('probe-addon')).render(createElement(counter.default as ComponentType));
    result.addon = 'rendered';
  } catch (error) {
    result.addon = `failed ${errorText(error)}`;
  }

  try {
    await load(`${settings.addons}inertia.js`);
  } catch (error) {
    result.refused =
      error instanceof Error
        ? { name: error.name, message: error.message }
        : { name: '', message: String(error) };
  }

  if (settings.crossOrigin !== null) {
    // The same server under the other loopback name: another origin than the page's.
    const url = new URL(settings.crossOrigin, location.href);
    url.hostname = url.hostname === 'localhost' ? '127.0.0.1' : 'localhost';

    try {
      await load(url.href);
      result.crossOrigin = 'ran';
    } catch (error) {
      result.crossOrigin = `blocked ${errorText(error)}`;
    }
  }

  if (settings.kit !== null) {
    try {
      const kit = await load(settings.kit);
      (kit.mount as (element: HTMLElement) => void)(mountPoint('probe-kit'));
      result.kit = 'mounted';
    } catch (error) {
      result.kit = `failed ${errorText(error)}`;
    }
  }

  result.done = true;
  window.cmsProbe = result;
}

void probe();
