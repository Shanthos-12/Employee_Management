<?php
/**************************************************************
 * E-Invoicing Portal Link Page - v1.0
 **************************************************************/
session_start();

// --- USER AUTHENTICATION ---
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/sfi_logo.png">
    <meta charset="UTF-8" />
    <title>E-Invoicing Portal (LHDN)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white">
    <header class="p-6 flex justify-between items-center bg-slate-800/50 backdrop-blur border-b border-slate-700 sticky top-0 z-20">
        <h1 class="text-2xl font-bold flex items-center gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
            <span>LHDN E-Invoicing Portal</span>
        </h1>
        <a href="index.php?dashboard=1" class="px-4 py-2 rounded-lg bg-gradient-to-r from-sky-500 to-indigo-500 hover:opacity-90 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
            <span>Dashboard</span>
        </a>
    </header>

    <main class="max-w-4xl mx-auto px-6 py-20 text-center">
        <section class="bg-white/10 backdrop-blur p-8 rounded-2xl shadow-xl border border-slate-700/50">
            <div class="flex justify-center mb-6">
                <img src="https://mytax.hasil.gov.my/assets/images/logo-mytax-white.svg" alt="MyTax Logo" class="h-12">
            </div>
            
            <h2 class="text-3xl font-bold text-white mb-4">Redirecting to MyTax Portal</h2>
            
            <p class="text-slate-300 max-w-2xl mx-auto mb-8">
                You are about to be redirected to the official LHDN MyTax portal for all e-invoicing matters. Please ensure you have your login credentials ready.
            </p>
            
            <a href="https://mytax.hasil.gov.my/" 
               target="_blank" 
               class="inline-block px-10 py-4 rounded-xl bg-gradient-to-r from-sky-500 to-indigo-500 hover:scale-105 transition-transform duration-300 font-semibold text-lg">
                Proceed to MyTax Portal
            </a>
            
            <p class="text-xs text-slate-500 mt-6">
                You are leaving our application. We are not responsible for the content of external websites.
            </p>
        </section>
    </main>
</body>
</html>