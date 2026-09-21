// Where this admin is: the page's origin AND the path the admin is served under (`/admin/` for
// the one Thallo ships). Never the origin alone — that is the site, and links built from it
// (the preview bar's Edit and Design, the billing return) land on the site's 404.

export function adminAddress(origin: string, base: string): string {
  return (origin + base).replace(/\/+$/, '')
}

/** The address of the admin the reader is using right now. */
export function runningAdminAddress(): string {
  return adminAddress(window.location.origin, import.meta.env.BASE_URL)
}
