import { COMPANY_BRAND, companyPhoneText } from "@/lib/company-brand";

export default function CompanyInvoiceHeader({
  documentKind,
  number,
  date,
  location,
  extra,
}: {
  documentKind: string;
  number: string;
  date: string;
  location?: string | null;
  extra?: string | null;
}) {
  return <>
    <div className="company-invoice-accent" aria-hidden="true"><span /></div>
    <header className="company-invoice-header">
      <div className="company-invoice-brand-block">
        <div className="company-invoice-brand-en">{COMPANY_BRAND.nameEn}</div>
        <strong>{COMPANY_BRAND.nameAr}</strong>
        <span>{COMPANY_BRAND.tagline}</span>
        <div className="company-invoice-phones" dir="ltr">{companyPhoneText()}</div>
      </div>
      <img className="company-invoice-logo" src="/al-hayah-logo.svg" alt="شعار شركة الحياة للتوكيلات والتسويق" />
    </header>
    <div className="company-invoice-meta">
      <div><span>{documentKind}</span><small>رقم المستند</small><strong dir="ltr">{number}</strong></div>
      <div><small>التاريخ</small><strong dir="ltr">{date}</strong>{location && <small>المخزن / الفرع: {location}</small>}{extra && <small>{extra}</small>}</div>
    </div>
  </>;
}
