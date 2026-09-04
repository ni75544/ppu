/**
 * @file
 * The Task 10C browser matrix, in one place because two files must agree on it.
 *
 * The dashboard gate walks this matrix; the authenticated-dashboard sentinel
 * derives from it how many login sessions and how many denied navigations the
 * walk is allowed to cause. If either file restated the matrix, the sentinel's
 * "expected" numbers would be a wish rather than a measurement — the two could
 * drift apart and the sentinel would still print green. Exporting the matrix from
 * a module neither of them owns makes that drift impossible.
 *
 * Nothing here is a policy decision. `PERSONAS` is the set of accounts the
 * dashboard's permission model splits on; `WIDTHS` is desktop and mobile, which
 * every gate in this harness is required to run at.
 */

/** The four accounts the Reservation Dashboard's field permissions split on. */
export const PERSONAS = ['viewer', 'manager', 'finance', 'administrator'];

/** Both required widths, as `[width, height, label]`. */
export const WIDTHS = [[1440, 900, 'desktop'], [390, 844, 'mobile']];
