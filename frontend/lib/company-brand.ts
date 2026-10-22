/** Company identity used by every customer/supplier-facing document. */
export const COMPANY_BRAND = {
  nameAr: "شركة الحياة للتوكيلات والتسويق",
  nameEn: "AL HAYAH For IMP & EXP",
  tagline: "أكبر مسوق للبراندات في مصر",
  phones: ["01099965065", "01270626723"],
  colors: { navy: "#171b50", gold: "#b7862c", ink: "#102b43", paper: "#fffdf8" },
};

export function companyPhoneText() {
  return COMPANY_BRAND.phones.join("  •  ");
}
