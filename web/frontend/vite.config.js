import { defineConfig, loadEnv } from "vite";
import { dirname } from "path";
import { fileURLToPath } from "url";
import react from "@vitejs/plugin-react";

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");
  const host = env.HOST
    ? env.HOST.replace(/https?:\/\//, "")
    : "localhost";

  if (
    process.env.npm_lifecycle_event === "build" &&
    !process.env.CI &&
    !env.SHOPIFY_API_KEY
  ) {
    console.warn(
      "\nBuilding the frontend app without an API key. The frontend build will not run without an API key. Set the SHOPIFY_API_KEY environment variable when running the build command.\n"
    );
  }

  const proxyOptions = {
    target: `http://127.0.0.1:${env.BACKEND_PORT}`,
    changeOrigin: false,
    secure: true,
    ws: false,
  };

  let hmrConfig;
  if (host === "localhost") {
    hmrConfig = {
      protocol: "ws",
      host: "localhost",
      port: 64999,
      clientPort: 64999,
    };
  } else {
    hmrConfig = {
      protocol: "wss",
      host: host,
      port: env.FRONTEND_PORT,
      clientPort: 443,
    };
  }

  return {
    root: dirname(fileURLToPath(import.meta.url)),
    plugins: [react()],
    define: {
      "process.env.SHOPIFY_API_KEY": JSON.stringify(env.SHOPIFY_API_KEY),
    },
    resolve: {
      preserveSymlinks: true,
    },
    server: {
      host: "localhost",
      port: env.FRONTEND_PORT,
      hmr: hmrConfig,
      proxy: {
        "^/(\\?.*)?$": proxyOptions,
        "^/api(/|(\\?.*)?$)": proxyOptions,
      },
    },
  };
});
