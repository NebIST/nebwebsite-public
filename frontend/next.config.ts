import type { NextConfig } from "next";

const isTec = process.env.DEPLOY_TARGET === 'tec';

const basePath = isTec ? "/DEV_PAGE_OF_IST" : "";

const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  images: { unoptimized: true },
  productionBrowserSourceMaps: false,
  basePath,
  assetPrefix: basePath ? `${basePath}/` : "",
  env: {
    NEXT_PUBLIC_BASE_PATH: basePath,
  },
};

module.exports = nextConfig;
