# Changelog

## v0.1.1 (2026-11-15)

No deprecation warning on PHP 8.5 (removed a `curl_close()` call, which has done nothing since PHP 8.0).
CI also tests PHP 8.5.

## 0.1.0 (2026-11-15)

First version, following the v3 API spec: `convert()` in one call; jobs (create, get, list, all, wait,
cancel, delete); file upload and download; operations; `me()`; webhook signature checks; typed exceptions;
retries that never create a job twice. PHP 8.1+, ext-curl and ext-json only.
