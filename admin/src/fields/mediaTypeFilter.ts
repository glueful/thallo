// Shared client-side companion to the media library's own `mediaType` filter
// (`MediaAdminController::applyTypeFilter`, mirrored by `MediaPickerModal`'s Library tab query) —
// AssetField's direct-drop upload and MediaPickerModal's own Upload tab both check a file's
// `File.type` against this SAME contract before spending an upload round-trip on a file the
// Library tab already wouldn't show. Backend validation stays authoritative either way; this is
// purely a fail-fast client-side check.
//
// Only 'image' / 'video' / 'audio' have a simple MIME-major-type prefix to check against
// (`${type}/…`). 'doc' has no such prefix (application/pdf, application/msword, …) and an
// absent/empty `mediaType` means "no filter" (e.g. DownloadsPanel's digital-download deliverables,
// which can be any file type) — both pass through unchecked, same as before this file existed.
export function isAcceptedMediaFile(file: File, mediaType: string | undefined): boolean {
  const type = mediaType ?? 'image'
  if (type !== 'image' && type !== 'video' && type !== 'audio') return true
  return file.type.startsWith(`${type}/`)
}
