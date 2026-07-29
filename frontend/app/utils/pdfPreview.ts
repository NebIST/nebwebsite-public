export function shouldUsePdfThumbnails() {
  if (typeof navigator === "undefined") return false;

  const ua = navigator.userAgent || "";
  const platform = navigator.platform || "";
  const maxTouchPoints = navigator.maxTouchPoints || 0;

  const isIosDevice = /iPad|iPhone|iPod/i.test(ua) || (platform === "MacIntel" && maxTouchPoints > 1);
  const isAndroidDevice = /Android/i.test(ua);
  const isMobileBrowser = /Mobi|Mobile/i.test(ua) || maxTouchPoints > 1;

  return !(isIosDevice || isAndroidDevice || isMobileBrowser);
}