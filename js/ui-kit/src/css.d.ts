// The kit imports its stylesheets for their side effect; the panel's bundler (Vite) puts them in
// the build. No stylesheet exports anything to TypeScript.
declare module '*.css';
