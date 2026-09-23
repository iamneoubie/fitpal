when instructed only refer to the given scope
do revisions and avoid giving snippets

when doing css text area resize should be none

take note when creating files:

- handlers doing only handler logics,
- queries doing only sql logics no non query logics,
- no need for view helpers

same with pages, css and js

- ages php file shouldn't be having any inline css and js
- js shouldn't have css too
- css logics should be only on appropriate css directory

when creating page structure

- avoid using hard coded < svg tags >
- use existing svg tags based on project tree
- svgs are in shared/assets/images/icons/
- avoid using window alert, observe how pages are structured
- use modals for user prompts not window alerts

using svgs

- always use appropriate svgs not non existent
- always use related or nearest related svgs

when it comes to buttons

- don't use global background transpararent, border outline and text color black
- the button rule is use primary for background and color text white when it's confrim process or positive process
- use background black and text color white on neutral buttons
- use background danger text color white on negative buttons like delete or remove process

when using modal:

- make sure it always has svg

avoid recommendations when not necessary or critical
and do step by step or one by one revisions, wait for my word 'proceed', 'next' of files since it's not snippets

when it comes to CSRF tokens across roles:

- every role (admin, customer, rider, restaurant) runs on the same PHP session (same cookie), since FitPal uses one shared session, not one per role
- never store a role's CSRF token under the shared key name `csrf_token` — give each role its own session key instead (e.g. `admin_csrf_token`, `customer_csrf_token`)
- reason: if two roles both read/write `$_SESSION['csrf_token']`, whichever role's handler runs `unset($_SESSION['csrf_token'])` on successful sign-in deletes the token the OTHER role's already-rendered form is relying on
- symptom this causes: whichever role logs in first works fine; the second role tried in the same browser session fails its first submit with "Security validation failed" and only succeeds on retry (because reloading the page regenerates a fresh, matching token)
- fix pattern: sign-in.php generates/reads its own `{role}_csrf_token`, the form field stays named `csrf_token` (POST field name can stay generic), and the matching handler checks `$_SESSION['{role}_csrf_token']` instead of the shared key
- on successful login, only unset that role's own token key — never touch the shared `csrf_token` key, since other roles in the same browser session may still depend on it
- apply this same per-role key pattern to any other session value that gets deleted/rotated on one role's success path (not just CSRF) if it could be read by another role's page
