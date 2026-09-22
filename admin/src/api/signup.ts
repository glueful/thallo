// Public workspace signup: begin → verify the emailed code → (resolve a conflict) → active.
// The /v1/signup endpoints carry no typed bodies in the OpenAPI spec, so they are posted with the
// same pre-auth JSON helper the password-reset flow uses.
import { postJson } from './auth'

export interface WorkspaceSignupInput {
  workspace_name: string
  slug: string
  first_name: string
  last_name: string
  email: string
  username: string
  password: string
}

/** What verifying the code (or continuing after a conflict) came to. */
export type SignupOutcome =
  | { status: 'active'; tenant_uuid: string; user_uuid: string }
  | { status: 'consumed'; outcome: string }
  | {
      status: 'conflict'
      code: 'SLUG_CONFLICT' | 'USERNAME_CONFLICT'
      continuation_token: string
      errors: Record<string, string>
    }
  | { status: 'provisioning'; tenant_uuid: string; continuation_token: string; message?: string }

export async function beginWorkspaceSignup(input: WorkspaceSignupInput): Promise<string> {
  const res = await postJson<{ intent_uuid?: string }>(
    '/v1/signup/workspace',
    {
      email: input.email,
      username: input.username,
      password: input.password,
      first_name: input.first_name,
      last_name: input.last_name,
      name: input.workspace_name,
      slug: input.slug,
    },
    'Could not start the signup. Please try again.',
  )
  const intent = res.data?.intent_uuid
  if (!intent) throw new Error('The signup did not start. Please try again.')
  return intent
}

export async function verifyWorkspaceSignup(
  intentUuid: string,
  code: string,
): Promise<SignupOutcome> {
  const res = await postJson<SignupOutcome>(
    '/v1/signup/verify',
    { intent_uuid: intentUuid, code },
    'That code did not work. Check it and try again.',
  )
  return res.data as SignupOutcome
}

/** After a conflict or an unfinished setup: change the slug or username, or just resume. */
export async function continueWorkspaceSignup(
  intentUuid: string,
  continuationToken: string,
  operation: 'resume' | 'change_slug' | 'change_username',
  payload: Record<string, string> = {},
): Promise<SignupOutcome & { continuation_token?: string }> {
  const res = await postJson<SignupOutcome & { continuation_token?: string }>(
    '/v1/signup/continue',
    {
      intent_uuid: intentUuid,
      continuation_token: continuationToken,
      operation_id: crypto.randomUUID(),
      operation,
      payload,
    },
    'Could not finish setting up the workspace. Please try again.',
  )
  return res.data as SignupOutcome & { continuation_token?: string }
}

export function resendWorkspaceSignupCode(intentUuid: string) {
  return postJson<unknown>(
    '/v1/signup/reverify',
    { intent_uuid: intentUuid },
    'Could not resend the code. Please try again.',
  )
}
