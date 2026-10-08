import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    id: "/",
    name: "NEXORA Distribution OS",
    short_name: "NEXORA",
    description: "إدارة التوزيع والمخازن والرحلات والمبيعات",
    lang: "ar",
    start_url: "/field",
    scope: "/",
    display: "standalone",
    orientation: "portrait",
    background_color: "#f4f7fb",
    theme_color: "#0b1730",
    icons: [
      {
        src: "/icons/nexora.svg",
        sizes: "any",
        type: "image/svg+xml",
        purpose: "any",
      },
      {
        src: "/icons/nexora.svg",
        sizes: "any",
        type: "image/svg+xml",
        purpose: "maskable",
      },
    ],
  };
}
