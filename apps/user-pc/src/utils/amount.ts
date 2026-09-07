/** Display only: round decimal amounts to 3 places, without grouping or padded zeroes.
 * Keep API decimal strings exact; never feed the formatted result into calculations.
 */
export function displayAmount(value: unknown): string {
  const text = String(value ?? "0").trim();
  if (!text) return "0";
  const match = text.match(/^([+-]?)(\d*)(?:\.(\d*))?(?:e([+-]?\d+))?$/i);
  if (!match || !(match[2] || match[3])) return text;
  const exponent = Number(match[4] || 0);
  if (!Number.isSafeInteger(exponent) || Math.abs(exponent) > 1000) return text;
  const digits = match[2] + (match[3] || "");
  const point = match[2].length + exponent;
  const whole = point <= 0 ? "0" : digits.slice(0, point).padEnd(point, "0");
  const fraction = point < 0 ? "0".repeat(-point) + digits : digits.slice(point);
  let units = BigInt(whole || "0") * 1000n + BigInt(fraction.slice(0, 3).padEnd(3, "0"));
  if (fraction.length > 3 && fraction[3] >= "5") units += 1n;
  const decimals = String(units % 1000n).padStart(3, "0").replace(/0+$/, "");
  return `${match[1] === "-" && units !== 0n ? "-" : ""}${units / 1000n}${decimals ? `.${decimals}` : ""}`;
}
