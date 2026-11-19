# Findings from testing

Defects found while building the test suite, confirmed by running the code
rather than read off it. Each one should end as a fix plus a test that pins it.
Open items first.

## Open

### F-01 · Critical · Anyone can create a working admin account

**Found** 2026-09-26, step 2 (access review in `tests/COVERAGE.md`).

`POST /api/admin/signup` needs no authentication and passes the whole request to
`Admin::create($request->all())` (`AdminsController::signup`). `verified` and
`active` are mass-assignable on `Admin`, so the caller chooses them, and the new
account can sign in at `POST /api/admin/login` straight away.

The account has no role, so role-checked screens refuse it (customers, orders,
staff answer 403). About 30 of the 137 admin GET routes do not check a
permission and answer it. Among them:

| Route | Returns |
|---|---|
| `GET api/admin/setting/sumup` | the SumUp **secret API key** (read from `.env`) and merchant details |
| `GET api/admin/payment/find` | the whole `payments` row. On the live database this includes the **live Stripe secret key** and gateway credentials. |
| `GET api/admin/role/all-permissions`, `all-roles` | the permission model |
| `GET api/admin/product/with-categories`, `updated-inventory/find/{id}` | catalogue, stock and cost data |
| `GET api/admin/subscriber/all-subscribers` | newsletter subscribers |

**Reproduced** in the isolated database (rolled back): sign up with
`verified=1, active=1`, sign in, then call the routes above with the token.
Admin **write** routes (POST/DELETE) have not been swept yet; some may be open
the same way (e.g. `POST api/admin/setting/sumup`, `POST api/admin/payment/save`).

**The admin panel never calls this route** (no reference in `admin-panel/`), so
removing it breaks nothing there.

**Suggested fix:** remove the route (or put it behind `auth:admin` plus a
staff-management permission); take `verified`/`active` out of `Admin::$fillable`
for requests that did not come from a superadmin; add a permission check to the
routes above. Then check the live `admins` table for accounts nobody
recognises, and rotate the Stripe and SumUp keys if any exist.

**Fixed locally 2026-11-18, not yet deployed** (pinned by
`tests/Isolated/AdminAccessSecurityTest.php`):
- the `admin/signup` route is removed;
- seller signup saves named fields only, so it can no longer send `verified`/`active`;
- `payment/find`, `setting/sumup` (GET and POST), `wallet-overview/all` and
  `subscriber/all-subscribers` now check `setting.view`/`setting.edit`,
  `wallet_log.view` and `subscriber.view`;
- `POST setting/sumup` refuses a value containing a line break, because it
  writes into `.env`.

**Still open (needs a staff login now):** 30 admin write routes have no
permission check, so any role can call them. They include:
- `store/action`, `setting/currency`, `templates/action` and `flash-discount/action`;
- `pos-order/action`, `manual-activation` and `user/address/action`;
- the image uploads.

Live still runs the old code until the backend is deployed. After that,
check the live `admins` table for accounts nobody recognises.

### F-02 · To confirm · Customer-area routes open to guests

Eleven routes under `api/v1/user/` answer without `auth:user`: addresses, wishlist,
save-for-later, profile, and voucher lists (see the access review in
`tests/COVERAGE.md`). Several are meant to serve guest baskets through a
`user_token`. Each needs a test proving a guest only ever sees their own
records and a stranger sees nothing. Not yet verified either way.

**2026-11-18 audit, run against the local API:** none of address/all, profile,
save-for-later/all, wishlist/all or user-vouchers returned anyone's records:
- with no token they answer 201/403;
- with an unknown `user_token` they answer 403;
- with `?user_id=1` they answer 201/403;
- user-vouchers lists only public vouchers.

Addresses and deletes are scoped to the signed-in user or the guest's own
`user_token`. Remaining gap: `api/admin/user/address/all?user_id=` has no
permission check, so any staff role can read any customer's addresses.

### F-03 · Critical · A customer token counted as the staff member with the same id

**Found** 2026-11-19 (audit). `ControllerHelper` (and `AdminsController`)
took `Auth::guard('admin')->user()` as the staff member. Passport resolves
any token to the admin with the same numeric id. Admin routes also run
`scope:admin`, so they refused customers. Storefront routes in those
controllers did not, so customer 101 acted as admin 101.

**Reproduced locally:** customer 101 read customer 102's address with
`v1/user/address/all?user_id=102`.

**Fixed locally, not deployed:** `ControllerHelper::staffFromToken()`
accepts only tokens with the `admin` scope.

### F-04 · High · Anyone could publish or rewrite product reviews

**Found** 2026-11-19. `v1/rating-review/store` and `update` (used by the
admin Reviews page) had no auth. A guest could:
- publish an approved review marked verified;
- rewrite any review by its id.

**Fixed locally, not deployed:** both now need staff with
`rating_review.delete`. The storefront "Verified" badge now shows only on
verified or order-linked reviews.

### F-05 · High · Rate limits never applied to write routes

**Found** 2026-11-19. `ClearCacheAfterWrite` runs `cache:clear` after
every POST/PUT/PATCH/DELETE. That emptied the store holding the throttle
counters. Staff login also had only the general 300/minute limit;
70 wrong passwords in a row were never refused.

**Fixed locally, not deployed:**
- `config/cache.php` gives rate limits their own store (`limiter`), which
  `cache:clear` does not touch;
- staff sign-in, reset and code routes are limited to 10/min with their own
  counter.

**Note for deployment:** every existing throttle now really applies (for
example 10/min per IP on customer sign-in).

All three are pinned by `tests/Isolated/AdminAccessSecurityTest.php`.

## Closed

_None yet._
