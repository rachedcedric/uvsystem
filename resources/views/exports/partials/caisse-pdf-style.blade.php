{{-- Pas de position:fixed (bug dompdf, voir dette-agent-pdf.blade.php). --}}
@page { margin: 50px 40px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #333; }
.banner { background: #1e293b; color: #fff; padding: 14px 20px; margin-bottom: 14px; }
.banner h1 { margin: 0 0 4px 0; font-size: 16px; }
.banner p { margin: 0; font-size: 9px; color: #cbd5e1; }
h2 { font-size: 12px; margin: 16px 0 6px 0; color: #1e293b; }
table { width: 100%; border-collapse: collapse; }
th { background: #f1f5f9; text-align: left; padding: 6px; font-size: 9px; border-bottom: 1px solid #cbd5e1; }
td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; }
.r { text-align: right; }
.in { color: #047857; }
.out { color: #be123c; }
.total td { font-weight: bold; background: #f8fafc; border-top: 1px solid #94a3b8; }
.foot { margin-top: 18px; font-size: 8px; color: #64748b; }
