import React, { Suspense, lazy } from "react";
import { Routes as ReactRouterRoutes, Route } from "react-router-dom";

/**
 * File-based routing.
 * @desc File-based routing that uses React Router under the hood.
 * To create a new route create a new .jsx file in `/pages` with a default export.
 *
 * Some examples:
 * * `/pages/index.jsx` matches `/`
 * * `/pages/blog/[id].jsx` matches `/blog/123`
 * * `/pages/[...catchAll].jsx` matches any URL not explicitly matched
 *
 * @param {object} pages value of import.meta.glob(). See https://vitejs.dev/guide/features.html#glob-import
 *
 * @return {Routes} `<Routes/>` from React Router, with a `<Route/>` for each file in `pages`
 */
export default function Routes({ pages }) {
  const routes = useRoutes(pages);
  const routeComponents = routes.map(({ path, component: Component }) => (
    <Route key={path} path={path} element={<Component />} />
  ));

  const notFoundRoute = routes.find(({ path }) => path === "/notFound");
  const NotFound = notFoundRoute ? notFoundRoute.component : () => null;

  return (
    <Suspense fallback={null}>
      <ReactRouterRoutes>
        {routeComponents}
        <Route path="*" element={<NotFound />} />
      </ReactRouterRoutes>
    </Suspense>
  );
}

function useRoutes(pages) {
  const routes = Object.keys(pages)
    .map((key) => {
      let path = key
        .replace(/^\.\//, "")
        .replace(/^pages/, "")
        .replace(/\.(t|j)sx?$/, "")
        /**
         * Replace /index with /
         */
        .replace(/\/index$/i, "/")
        /**
         * Only lowercase the first letter. This allows the developer to use camelCase
         * dynamic paths while ensuring their standard routes are normalized to lowercase.
         */
        .replace(/\b[A-Z]/, (firstLetter) => firstLetter.toLowerCase())
        /**
         * Convert /[handle].jsx and /[...handle].jsx to /:handle.jsx for react-router-dom
         */
        .replace(/\[(?:[.]{3})?(\w+?)\]/g, (_match, param) => `:${param}`);

      if (path.endsWith("/") && path !== "/") {
        path = path.substring(0, path.length - 1);
      }

      if (!path.startsWith("/")) {
        path = "/" + path;
      }

      let component = pages[key].default || pages[key];

      // If the component is a function but doesn't look like a React component
      // (e.g., if it's a dynamic import loader)
      // and it's from a glob that might not be eager.
      if (typeof component === "function" && !component.prototype?.isReactComponent && component.name !== component.name.toUpperCase()) {
        // Wrap in lazy just in case it's a loader
        const loader = component;
        component = lazy(async () => {
          const result = loader();
          if (result instanceof Promise) {
            return result;
          }
          return { default: result };
        });
      }

      if (!component) {
        console.warn(`${key} doesn't export a default React component`);
      }

      return {
        path,
        component,
      };
    })
    .filter((route) => route.component);

  return routes;
}
