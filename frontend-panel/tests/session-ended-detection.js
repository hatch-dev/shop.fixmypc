/*
 * The browser's half of "you have been logged out".
 *
 * This exists because the first attempt only checked `status === 401`. Most
 * refusals in this API answer HTTP 201 and carry the real outcome in the body,
 * which axios hands to the SUCCESS handler - so a revoked session arrived with
 * no error at all and the page kept its signed-in shell over a dead session.
 *
 *     cd frontend-panel && node tests/session-ended-detection.js
 */
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(
  path.join(__dirname, '..', 'services', 'service.js'), 'utf8'
);

// Pull the detector out of the module rather than importing the module, which
// would drag in axios and the Nuxt config.
const match = source.match(/const isSessionEnded = \(payload, httpStatus\) => \{[\s\S]*?\n\}/);

if (!match) {
  console.log('FAIL  isSessionEnded not found in services/service.js');
  process.exit(1);
}

const isSessionEnded = new Function(`${match[0]}; return isSessionEnded;`)();

let fails = 0;
const check = (name, cond) => {
  console.log((cond ? '  PASS  ' : '  FAIL  ') + name);
  if (!cond) fails++;
};

console.log('\nShapes that mean the session is over:');
check('real HTTP 401',
  isSessionEnded({message: 'Your session has ended.'}, 401));
check('the server session_ended flag',
  isSessionEnded({session_ended: true, status: 401}, 401));
check('the app 201-with-body-204 "Unauthenticated." shape',
  isSessionEnded({data: null, status: 204, message: 'Unauthenticated.'}, 201));
check('session_ended flag even on a 200',
  isSessionEnded({session_ended: true}, 200));

console.log('\nShapes that must NOT log anybody out:');
check('an ordinary success', !isSessionEnded({data: {id: 1}, status: 200}, 200));
check('a signed-in profile', !isSessionEnded({data: {is_logged_in: true}, status: 200}, 200));
check('a guest profile (200, body status 201)',
  !isSessionEnded({data: {form: ["We couldn't found anything."]}, status: 201}, 200));
check('a validation refusal (body status 201)',
  !isSessionEnded({data: {form: ['Enter the company name.']}, status: 201}, 201));
check('a generic controller error',
  !isSessionEnded({data: {form: ['Something went wrong']}, status: 201}, 200));
check('a 500', !isSessionEnded({message: 'Server Error'}, 500));
check('a 404', !isSessionEnded({message: 'Not found'}, 404));
check('a 422 validation response', !isSessionEnded({errors: {}}, 422));
check('no payload at all', !isSessionEnded(undefined, undefined));
check('a null payload', !isSessionEnded(null, 200));
check('a string payload', !isSessionEnded('plain text', 200));
check('an array payload', !isSessionEnded([], 200));

console.log('\nThe interceptor is wired to both axios paths:');
check('registered on the success handler',
  /interceptors\.response\.use\(\s*\(response\)\s*=>\s*\{[\s\S]*?isSessionEnded\(response/.test(source));
check('registered on the error handler',
  /\(error\)\s*=>\s*\{[\s\S]*?isSessionEnded\(error\?\.response\?\.data/.test(source));
check('handler is settable from a plugin',
  /setUnauthorizedHandler/.test(source) && /setUnauthorizedHandler,/.test(source));

console.log(fails ? `\n${fails} check(s) FAILED` : '\nAll session-detection checks passed');
process.exitCode = fails ? 1 : 0;
