// React Aria imports useSyncExternalStore from the CommonJS package use-sync-external-store/shim,
// which calls require('react'). An ES module build that leaves `react` to the import map cannot
// keep that call, so vite.config.ts points the shim here: React 19 has the hook itself.

export { useSyncExternalStore } from 'react';
