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

### F-02 · To confirm · Customer-area routes open to guests

Eleven routes under `api/v1/user/` answer without `auth:user`: addresses, wishlist,
save-for-later, profile, and voucher lists (see the access review in
`tests/COVERAGE.md`). Several are meant to serve guest baskets through a
`user_token`. Each needs a test proving a guest only ever sees their own
records and a stranger sees nothing. Not yet verified either way.

## Closed

_None yet._
