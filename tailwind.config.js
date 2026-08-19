/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./index.html"],
  theme: {
    extend: {
      fontFamily: {
        sans: ["Manrope", "sans-serif"],
        display: ["Space Grotesk", "sans-serif"],
      },
      colors: {
        charcoal: "#151515",
        ember: "#f0441c",
        amber: "#ffb923",
        signal: "#ff6b22",
        paper: "#fffdf8",
      },
      boxShadow: {
        premium: "0 24px 70px rgba(20, 18, 15, .13)",
        glow: "0 24px 90px rgba(240, 68, 28, .22)",
      },
    },
  },
  plugins: [],
};
