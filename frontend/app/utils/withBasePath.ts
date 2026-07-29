const BASE_PATH = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

function isExternalOrSpecialUrl(url: string) {
  return (
    url.startsWith("http://") ||
    url.startsWith("https://") ||
    url.startsWith("//") ||
    url.startsWith("data:") ||
    url.startsWith("#")
  );
}


export function withBasePath(path: string) {
  if (!path) return BASE_PATH;
  if (isExternalOrSpecialUrl(path)) return path;

  const normalized = path.startsWith("/") ? path : `/${path}`;
  return BASE_PATH ? `${BASE_PATH}${normalized}` : normalized;
}

export const basePath = BASE_PATH;
