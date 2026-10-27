<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}
$userId = (int) $_SESSION['user_id'];
$configMax = (int) (require __DIR__ . '/../database/ocr_config.php')['max_file_size'];
$maxMb = round($configMax / (1024 * 1024), 1);
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>
<style>
    .ocr-page {
        max-width: 920px;
        margin: 0 auto;
        padding-bottom: 60px
    }

    .ocr-hero {
        background: linear-gradient(135deg, #078f86 0%, #0f766e 60%, #0e7490 100%);
        color: #fff;
        border-radius: 22px;
        padding: 28px 34px;
        margin-bottom: 22px;
        box-shadow: 0 14px 36px rgba(7, 143, 134, .26)
    }

    .ocr-hero h1 {
        margin: 0 0 8px;
        font-size: 26px;
        font-weight: 800
    }

    .ocr-hero p {
        margin: 0;
        font-size: 14px;
        opacity: .94;
        max-width: 600px
    }

    .ocr-drop {
        position: relative;
        background: #fff;
        border: 2px dashed #b7d8d4;
        border-radius: 18px;
        padding: 38px 22px;
        text-align: center;
        cursor: pointer;
        transition: all .2s ease;
        margin-bottom: 20px
    }

    .ocr-drop:hover,
    .ocr-drop.dragover {
        border-color: #078f86;
        background: #f0faf8
    }

    .ocr-drop .ocr-drop-icon {
        font-size: 52px;
        line-height: 1;
        margin-bottom: 10px
    }

    .ocr-drop .ocr-drop-title {
        font-size: 18px;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 6px
    }

    .ocr-drop .ocr-drop-sub {
        font-size: 13px;
        color: #64748b
    }

    .ocr-drop input[type=file] {
        display: none
    }

    .ocr-preview-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 12px;
        margin-bottom: 20px
    }

    .ocr-preview-item {
        position: relative;
        background: #fff;
        border: 1.5px solid #e2f0ee;
        border-radius: 12px;
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .04)
    }

    .ocr-preview-item .pv-thumb {
        width: 100%;
        height: 110px;
        border-radius: 8px;
        overflow: hidden;
        background: #f1f5f9;
        display: grid;
        place-items: center
    }

    .ocr-preview-item .pv-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover
    }

    .ocr-preview-item .pv-thumb .pv-pdf {
        font-size: 11px;
        color: #475569;
        font-weight: 700;
        text-align: center;
        padding: 6px
    }

    .ocr-preview-item .pv-name {
        font-size: 11px;
        color: #475569;
        line-height: 1.3;
        word-break: break-all;
        font-weight: 600
    }

    .ocr-preview-item .pv-close {
        position: absolute;
        top: -8px;
        right: -8px;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #ef4444;
        color: #fff;
        border: 2px solid #fff;
        cursor: pointer;
        display: grid;
        place-items: center;
        font-size: 14px;
        font-weight: 800;
        padding: 0;
        line-height: 1;
        box-shadow: 0 2px 6px rgba(0, 0, 0, .18)
    }

    .ocr-preview-item .pv-close:hover {
        background: #dc2626;
        transform: scale(1.08)
    }

    .ocr-actions {
        display: flex;
        gap: 12px;
        justify-content: center;
        margin-bottom: 22px;
        flex-wrap: wrap
    }

    .ocr-btn {
        border: 0;
        border-radius: 14px;
        padding: 13px 28px;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        transition: all .15s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-family: inherit
    }

    .ocr-btn.primary {
        background: linear-gradient(135deg, #078f86, #0e7490);
        color: #fff;
        box-shadow: 0 8px 20px rgba(7, 143, 134, .28)
    }

    .ocr-btn.primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 26px rgba(7, 143, 134, .36)
    }

    .ocr-btn.primary:disabled {
        opacity: .5;
        cursor: not-allowed;
        transform: none
    }

    .ocr-btn.ghost {
        background: #fff;
        color: #475569;
        border: 1px solid #e2e8f0
    }

    .ocr-btn.ghost:hover {
        background: #f8fafc;
        color: #0f172a
    }

    .ocr-processing {
        display: none;
        text-align: center;
        padding: 60px 20px;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
        border: 1px solid #eef2f3
    }

    .ocr-processing.show {
        display: block
    }

    .ocr-spinner {
        width: 64px;
        height: 64px;
        border: 5px solid #e2e8f0;
        border-top-color: #078f86;
        border-radius: 50%;
        margin: 0 auto 20px;
        animation: ocrSpin 1s linear infinite
    }

    @keyframes ocrSpin {
        to {
            transform: rotate(360deg)
        }
    }

    .ocr-processing h3 {
        margin: 0 0 8px;
        font-size: 20px;
        font-weight: 800;
        color: #0f172a
    }

    .ocr-processing p {
        margin: 0;
        color: #64748b;
        font-size: 14px
    }

    .ocr-error {
        display: none;
        background: #fee2e2;
        color: #991b1b;
        padding: 16px 20px;
        border-radius: 14px;
        font-size: 14px;
        margin-bottom: 18px;
        border: 1px solid #fecaca;
        font-weight: 600
    }

    .ocr-error.show {
        display: block
    }

    .ocr-results {
        display: none
    }

    .ocr-results.show {
        display: block
    }

    .ocr-panel {
        background: #fff;
        border-radius: 18px;
        padding: 20px 22px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
        border: 1px solid #eef2f3;
        margin-bottom: 20px
    }

    .ocr-panel h3 {
        margin: 0 0 16px;
        font-size: 15px;
        font-weight: 800;
        color: #0d9488;
        display: flex;
        align-items: center;
        gap: 8px;
        padding-bottom: 12px;
        border-bottom: 1px solid #f1f5f9
    }

    .ocr-panel h3 .badge {
        background: #ccfbf1;
        color: #0d9488;
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 8px;
        margin-left: auto;
        font-weight: 700
    }

    .ocr-mlist {
        display: flex;
        flex-direction: column;
        gap: 12px
    }

    .ocr-mcard {
        position: relative;
        display: grid;
        grid-template-columns: 64px 1fr auto;
        gap: 14px;
        align-items: center;
        padding: 14px 46px 14px 14px;
        border: 1.5px solid #e2f0ee;
        background: #f8fbfa;
        border-radius: 14px
    }

    .ocr-mcard:hover {
        box-shadow: 0 6px 18px rgba(7, 143, 134, .14)
    }

    .ocr-mcard.restricted {
        background: #fff7f7;
        border-color: #fecaca
    }

    .ocr-mcard .thumb {
        width: 64px;
        height: 64px;
        border-radius: 10px;
        overflow: hidden;
        background: #e6f7f4;
        display: grid;
        place-items: center
    }

    .ocr-mcard .thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover
    }

    .ocr-mcard .thumb svg {
        width: 38px;
        height: 38px;
        opacity: .6
    }

    .ocr-mcard .info {
        min-width: 0
    }

    .ocr-mcard .info .name {
        font-size: 15px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.25;
        margin-bottom: 3px
    }

    .ocr-mcard .info .meta {
        font-size: 12px;
        color: #64748b;
        line-height: 1.35
    }

    .ocr-mcard .info .meta span {
        margin-right: 8px
    }

    .ocr-mcard .info .restricted-tag {
        display: inline-block;
        background: #fee2e2;
        color: #dc2626;
        font-size: 10px;
        font-weight: 800;
        padding: 3px 7px;
        border-radius: 6px;
        border: 1px solid #fca5a5;
        margin-top: 5px;
        text-transform: uppercase;
        letter-spacing: .4px
    }

    .ocr-mcard .x-del {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 1px solid #fecaca;
        background: #fff;
        color: #dc2626;
        cursor: pointer;
        display: grid;
        place-items: center;
        font-size: 16px;
        font-weight: 800;
        line-height: 1;
        padding: 0
    }

    .ocr-mcard .x-del:hover {
        background: #fee2e2;
        border-color: #f87171;
        transform: scale(1.08)
    }

    .match-badge {
        display: inline-block;
        font-size: 10px;
        font-weight: 800;
        padding: 2px 7px;
        border-radius: 6px;
        text-transform: uppercase;
        letter-spacing: .4px;
        vertical-align: middle;
        margin-left: 6px
    }

    .match-badge.exact {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #86efac
    }

    .match-badge.generic {
        background: #dbeafe;
        color: #1e40af;
        border: 1px solid #93c5fd
    }

    .match-badge.fuzzy {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a
    }

    .match-badge.manual {
        background: #f3e8ff;
        color: #7e22ce;
        border: 1px solid #d8b4fe
    }

    .ocr-source {
        font-size: 11px;
        color: #64748b;
        margin-top: 6px;
        padding: 5px 8px;
        background: #f1f5f9;
        border-radius: 6px;
        line-height: 1.35
    }

    .ocr-source em {
        color: #0f766e;
        font-style: normal;
        font-weight: 700
    }

    .ocr-qty {
        display: inline-flex;
        align-items: center;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        overflow: hidden
    }

    .ocr-qty button {
        width: 32px;
        height: 32px;
        border: 0;
        background: #f1f5f9;
        color: #0f766e;
        font-size: 18px;
        font-weight: 800;
        line-height: 1;
        cursor: pointer;
        padding: 0
    }

    .ocr-qty button:hover:not(:disabled) {
        background: #d5efeb
    }

    .ocr-qty button:disabled {
        opacity: .35;
        cursor: not-allowed
    }

    .ocr-qty input {
        width: 46px;
        height: 32px;
        border: 0;
        outline: none;
        text-align: center;
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
        background: #fff;
        -moz-appearance: textfield
    }

    .ocr-qty input::-webkit-outer-spin-button,
    .ocr-qty input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0
    }

    .ocr-mcard .qty-price-col {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px
    }

    .ocr-mcard .qty-price-col .unit {
        font-size: 11px;
        color: #94a3b8
    }

    .ocr-mcard .qty-price-col .total {
        font-size: 16px;
        font-weight: 800;
        color: #078f86
    }

    .ocr-empty {
        text-align: center;
        padding: 40px 16px;
        color: #94a3b8;
        font-size: 13.5px;
        font-style: italic
    }

    .ocr-empty strong {
        display: block;
        font-size: 15px;
        color: #64748b;
        font-style: normal;
        margin-bottom: 6px;
        font-weight: 800
    }

    /* Add Medicine bar with X inside open button */
    .ocr-addbar {
        margin-top: 16px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        position: relative
    }

    .ocr-addbtn {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 46px 12px 20px;
        border: 2px dashed #a7d4ce;
        background: #f0faf8;
        color: #078f86;
        font-weight: 800;
        font-size: 14px;
        border-radius: 12px;
        cursor: pointer;
        transition: all .15s ease;
        font-family: inherit
    }

    .ocr-addbtn:hover {
        border-color: #078f86;
        background: #e0f7f3
    }

    .ocr-addbtn.open {
        border-style: solid;
        border-color: #078f86;
        background: #078f86;
        color: #fff
    }

    .ocr-addbtn .ocr-addbtn-x {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: #fff;
        color: #dc2626;
        border: 1px solid #fecaca;
        font-size: 14px;
        font-weight: 800;
        line-height: 1;
        cursor: pointer;
        display: none;
        padding: 0;
        place-items: center
    }

    .ocr-addbtn.open .ocr-addbtn-x {
        display: grid
    }

    .ocr-addbtn .ocr-addbtn-x:hover {
        background: #fee2e2;
        border-color: #f87171
    }

    .ocr-searchbox {
        display: none;
        position: relative
    }

    .ocr-searchbox.show {
        display: block;
        animation: ocrFade .2s ease
    }

    @keyframes ocrFade {
        from {
            opacity: 0;
            transform: translateY(-6px)
        }

        to {
            opacity: 1;
            transform: translateY(0)
        }
    }

    .ocr-searchbox input {
        width: 100%;
        box-sizing: border-box;
        padding: 13px 42px 13px 16px;
        border: 1.5px solid #078f86;
        border-radius: 12px;
        font-size: 14px;
        font-family: inherit;
        outline: none;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(7, 143, 134, .10)
    }

    .ocr-searchbox .srch-icon {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 15px;
        color: #078f86;
        pointer-events: none
    }

    .ocr-searchresults {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1.5px solid #d7e5e3;
        border-radius: 14px;
        box-shadow: 0 14px 34px rgba(15, 23, 42, .16);
        max-height: 360px;
        overflow-y: auto;
        z-index: 200;
        display: none
    }

    .ocr-searchresults.show {
        display: block
    }

    .ocr-searchresults::-webkit-scrollbar {
        width: 6px
    }

    .ocr-searchresults::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 6px
    }

    .ocr-addrow {
        display: grid;
        grid-template-columns: 44px 1fr auto;
        gap: 12px;
        align-items: center;
        padding: 11px 14px;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer
    }

    .ocr-addrow:last-child {
        border-bottom: 0
    }

    .ocr-addrow:hover {
        background: #f0faf8
    }

    .ocr-addrow.added {
        opacity: .5;
        cursor: default;
        background: #f8fafc
    }

    .ocr-addrow .thumb {
        width: 44px;
        height: 44px;
        border-radius: 9px;
        overflow: hidden;
        background: #e6f7f4;
        display: grid;
        place-items: center;
        flex-shrink: 0
    }

    .ocr-addrow .thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover
    }

    .ocr-addrow .thumb svg {
        width: 26px;
        height: 26px;
        opacity: .6
    }

    .ocr-addrow .ar-info {
        min-width: 0
    }

    .ocr-addrow .ar-name {
        font-size: 13.5px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.2;
        margin-bottom: 2px
    }

    .ocr-addrow .ar-name small {
        color: #64748b;
        font-weight: 600;
        font-size: 11.5px;
        margin-left: 4px
    }

    .ocr-addrow .ar-meta {
        font-size: 11.5px;
        color: #64748b;
        line-height: 1.3
    }

    .ocr-addrow .ar-meta span {
        margin-right: 8px
    }

    .ocr-addrow .ar-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 4px
    }

    .ocr-addrow .ar-price {
        font-size: 13px;
        font-weight: 800;
        color: #078f86;
        white-space: nowrap;
        text-align: right
    }

    .ocr-addrow .ar-price small {
        display: block;
        font-size: 10.5px;
        color: #94a3b8;
        font-weight: 600
    }

    .ocr-addrow .ar-add-btn {
        background: #16a34a;
        color: #fff;
        border: 0;
        font-size: 11px;
        font-weight: 800;
        padding: 6px 12px;
        border-radius: 9px;
        cursor: pointer;
        white-space: nowrap
    }

    .ocr-addrow.added .ar-add-btn {
        background: #94a3b8;
        cursor: default
    }

    .ocr-addhint {
        padding: 22px 16px;
        text-align: center;
        color: #94a3b8;
        font-size: 13px;
        font-style: italic
    }

    .ocr-calc {
        position: sticky;
        bottom: 16px;
        z-index: 50;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        background: linear-gradient(135deg, #0f172a, #1e293b);
        color: #fff;
        border-radius: 18px;
        padding: 18px 26px;
        box-shadow: 0 18px 40px rgba(15, 23, 42, .35);
        flex-wrap: wrap
    }

    .ocr-calc .calc-left {
        display: flex;
        flex-direction: column;
        gap: 2px
    }

    .ocr-calc .calc-left .label {
        font-size: 12px;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .6px;
        font-weight: 700
    }

    .ocr-calc .calc-left .qty-note {
        font-size: 12px;
        color: #cbd5e1
    }

    .ocr-calc .calc-left .total {
        font-size: 26px;
        font-weight: 800;
        color: #5eead4;
        letter-spacing: -.5px
    }

    .ocr-calc .ocr-btn.primary {
        background: linear-gradient(135deg, #0d9488, #0e7490);
        box-shadow: 0 8px 20px rgba(13, 148, 136, .4)
    }

    .ocr-approval-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .55);
        backdrop-filter: blur(3px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        z-index: 100000
    }

    .ocr-approval-card {
        position: relative;
        width: min(560px, 100%);
        max-height: calc(100vh - 40px);
        overflow: auto;
        background: #fff;
        border-radius: 22px;
        padding: 32px 34px 28px;
        box-shadow: 0 24px 60px rgba(15, 23, 42, .35);
        text-align: center
    }

    .ocr-approval-close {
        position: absolute;
        right: 16px;
        top: 14px;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        border: 0;
        background: #f1f5f9;
        color: #334155;
        font-size: 22px;
        line-height: 1;
        cursor: pointer
    }

    .ocr-approval-icon {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        display: grid;
        place-items: center;
        font-size: 44px;
        margin: 0 auto 18px
    }

    .ocr-approval-card h2 {
        margin: 0 0 12px;
        font-size: 21px;
        font-weight: 800;
        color: #0f172a
    }

    .ocr-approval-desc {
        margin: 0 0 20px;
        font-size: 14px;
        color: #475569;
        line-height: 1.55
    }

    .ocr-approval-info {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 18px;
        text-align: left;
        margin-bottom: 22px
    }

    .ocr-approval-row {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        padding: 8px 0;
        border-bottom: 1px dashed #e2e8f0;
        font-size: 13.5px;
        line-height: 1.5
    }

    .ocr-approval-row:last-child {
        border-bottom: 0;
        padding-bottom: 0
    }

    .ocr-approval-row:first-child {
        padding-top: 0
    }

    .ocr-approval-row .lbl {
        color: #64748b;
        font-weight: 700;
        flex: 0 0 auto;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: .4px;
        padding-top: 2px
    }

    .ocr-approval-row .val {
        color: #0f172a;
        font-weight: 700;
        text-align: right
    }

    .ocr-approval-actions {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap
    }

    .ocr-approval-btn {
        border: 0;
        border-radius: 12px;
        padding: 12px 22px;
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
        font-family: inherit
    }

    .ocr-approval-btn.ghost {
        background: #f1f5f9;
        color: #475569
    }

    .ocr-approval-btn.primary {
        background: linear-gradient(135deg, #078f86, #0e7490);
        color: #fff;
        box-shadow: 0 8px 20px rgba(7, 143, 134, .28)
    }

    @media(max-width:640px) {
        .ocr-mcard {
            grid-template-columns: 52px 1fr;
            padding-right: 44px;
            row-gap: 10px
        }

        .ocr-mcard .thumb {
            width: 52px;
            height: 52px
        }

        .ocr-mcard .qty-price-col {
            grid-column: 2;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            width: 100%
        }

        .ocr-calc {
            padding: 14px 18px
        }

        .ocr-calc .calc-left .total {
            font-size: 22px
        }
    }
</style>

<div class="ocr-page">
    <div class="ocr-hero">
        <h1>📸 Prescription Scanner</h1>
        <p>Upload one or more prescriptions (photos/PDFs). We'll read them, match against our catalogue, and let you add
            the medicines to your cart.</p>
    </div>

    <div class="ocr-error" id="ocrError"></div>

    <div class="ocr-drop" id="ocrDrop">
        <div class="ocr-drop-icon">📄</div>
        <div class="ocr-drop-title">Drag &amp; drop prescriptions here</div>
        <div class="ocr-drop-sub">or click to browse — multiple files allowed · JPG, PNG, WEBP, PDF (max <?= $maxMb ?> MB
            each)</div>
        <input type="file" id="ocrFileInput"
            accept="image/jpeg,image/png,image/webp,image/gif,image/bmp,image/tiff,application/pdf" multiple>
    </div>

    <div class="ocr-preview-list" id="ocrPreviewList"></div>

    <div class="ocr-actions" id="ocrActions" style="display:none;">
        <button type="button" class="ocr-btn primary" id="ocrScanBtn">🔍 Scan All Prescriptions</button>
    </div>

    <div class="ocr-processing" id="ocrProcessing">
        <div class="ocr-spinner"></div>
        <h3>Reading your prescriptions…</h3>
        <p>This may take a bit longer with multiple files.</p>
    </div>

    <div class="ocr-results" id="ocrResults">
        <div class="ocr-panel">
            <h3>✅ Scanned Medicines <span class="badge" id="ocrMatchedBadge">0</span></h3>
            <div class="ocr-mlist" id="ocrMatchedList"></div>

            <div class="ocr-addbar">
                <button type="button" class="ocr-addbtn" id="ocrAddToggle">
                    <span class="ocr-addbtn-icon">＋ Add Medicine</span>
                    <span class="ocr-addbtn-x" id="ocrAddClose" aria-label="Close">✕</span>
                </button>
                <div class="ocr-searchbox" id="ocrSearchBox">
                    <input type="text" id="ocrSearchInput" placeholder="Search medicine name, generic or company…"
                        autocomplete="off">
                    <span class="srch-icon">🔍</span>
                    <div class="ocr-searchresults" id="ocrSearchResults">
                        <div class="ocr-addhint">Type at least 2 letters to search the catalogue.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ocr-calc" id="ocrCalc">
            <div class="calc-left">
                <span class="label">Estimated total</span>
                <span class="qty-note" id="ocrQtyNote">0 items</span>
                <span class="total" id="ocrTotal">0 tk.</span>
            </div>
            <button type="button" class="ocr-btn primary" id="ocrBuyBtn">🛒 Proceed to Checkout →</button>
        </div>

        <div style="text-align:center;margin-top:18px;">
            <button type="button" class="ocr-btn ghost" id="ocrScanAgain">← Scan another prescription</button>
        </div>
    </div>

    <form id="ocrBuyForm" method="post" action="actions/ocr_buy.php" style="display:none;"></form>
</div>

<script>
    (function () {
        const drop = document.getElementById('ocrDrop');
        const input = document.getElementById('ocrFileInput');
        const previewList = document.getElementById('ocrPreviewList');
        const actions = document.getElementById('ocrActions');
        const scanBtn = document.getElementById('ocrScanBtn');
        const processing = document.getElementById('ocrProcessing');
        const results = document.getElementById('ocrResults');
        const errBox = document.getElementById('ocrError');
        const scanAgain = document.getElementById('ocrScanAgain');
        const buyBtn = document.getElementById('ocrBuyBtn');
        const buyForm = document.getElementById('ocrBuyForm');
        const matchedList = document.getElementById('ocrMatchedList');
        const matchedBadge = document.getElementById('ocrMatchedBadge');
        const totalEl = document.getElementById('ocrTotal');
        const qtyNote = document.getElementById('ocrQtyNote');
        const addToggle = document.getElementById('ocrAddToggle');
        const addClose = document.getElementById('ocrAddClose');
        const searchBox = document.getElementById('ocrSearchBox');
        const searchInput = document.getElementById('ocrSearchInput');
        const searchResults = document.getElementById('ocrSearchResults');

        const MAX_BYTES = <?= $configMax ?>;
        const MAX_MB = <?= $maxMb ?>;
        let pendingFiles = [];   /* [{file, id}] */
        let matchedData = [];
        let prescriptionTokens = [];  /* tokens returned from OCR */
        let searchTimer = null;
        let searchOpen = false;

        function fmtTk(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' tk.'; }
        function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }
        function showError(msg) { errBox.textContent = msg; errBox.classList.add('show'); }
        function hideError() { errBox.classList.remove('show'); errBox.textContent = ''; }
        function resetUI() { previewList.innerHTML = ''; actions.style.display = 'none'; processing.classList.remove('show'); results.classList.remove('show'); }

        /* -------- File picker -------- */
        drop.addEventListener('click', () => input.click());
        drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('dragover'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
        drop.addEventListener('drop', e => {
            e.preventDefault(); drop.classList.remove('dragover');
            const fl = e.dataTransfer.files;
            if (fl && fl.length) addFiles(fl);
        });
        input.addEventListener('change', e => {
            const fl = e.target.files;
            if (fl && fl.length) addFiles(fl);
            input.value = '';
        });

        function addFiles(fileList) {
            hideError();
            const arr = Array.from(fileList);
            for (const f of arr) {
                if (f.size > MAX_BYTES) { showError('"' + f.name + '" is too large (max ' + MAX_MB + ' MB).'); continue; }
                if (!/\.(jpe?g|png|webp|gif|bmp|tiff?|pdf)$/i.test(f.name)) { showError('"' + f.name + '" is not allowed.'); continue; }
                pendingFiles.push({ file: f, id: 'pv_' + Math.random().toString(36).slice(2) });
            }
            renderPreviews();
        }

        function renderPreviews() {
            previewList.innerHTML = '';
            pendingFiles.forEach(pf => {
                const item = document.createElement('div');
                item.className = 'ocr-preview-item';
                item.dataset.id = pf.id;

                const thumb = document.createElement('div');
                thumb.className = 'pv-thumb';
                if (pf.file.type === 'application/pdf') {
                    const d = document.createElement('div');
                    d.className = 'pv-pdf';
                    d.textContent = '📄 PDF';
                    thumb.appendChild(d);
                } else {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(pf.file);
                    img.alt = pf.file.name;
                    thumb.appendChild(img);
                }

                const name = document.createElement('div');
                name.className = 'pv-name';
                name.textContent = pf.file.name;

                const close = document.createElement('button');
                close.type = 'button';
                close.className = 'pv-close';
                close.setAttribute('aria-label', 'Remove');
                close.textContent = '×';
                close.addEventListener('click', () => {
                    pendingFiles = pendingFiles.filter(x => x.id !== pf.id);
                    renderPreviews();
                });

                item.appendChild(close);
                item.appendChild(thumb);
                item.appendChild(name);
                previewList.appendChild(item);
            });

            actions.style.display = pendingFiles.length > 0 ? 'flex' : 'none';
        }

        /* -------- Scan -------- */
        scanBtn.addEventListener('click', () => {
            if (!pendingFiles.length) { showError('Please choose at least one file.'); return; }
            hideError();
            processing.classList.add('show');
            actions.style.display = 'none';
            results.classList.remove('show');

            const fd = new FormData();
            pendingFiles.forEach(pf => fd.append('prescriptions[]', pf.file, pf.file.name));

            fetch('actions/ocr_process.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
                .then(r => r.json().then(d => ({ status: r.status, data: d })))
                .then(({ status, data }) => {
                    processing.classList.remove('show');
                    if (!data || !data.ok) {
                        showError((data && data.error) ? data.error : 'Could not process the prescriptions.');
                        actions.style.display = 'flex';
                        return;
                    }
                    renderResults(data);
                })
                .catch(err => {
                    processing.classList.remove('show');
                    actions.style.display = 'flex';
                    showError('Network error. Please try again.');
                    console.error(err);
                });
        });

        function renderResults(data) {
            results.classList.add('show');
            actions.style.display = 'none';
            prescriptionTokens = (data.tokens || []).map(t => t.token);
            matchedData = (data.matched || []).map(m => ({ ...m, qty: 1 }));
            rebuildMatchedList();
            updateTotals();
        }

        function rebuildMatchedList() {
            matchedBadge.textContent = matchedData.length;
            matchedList.innerHTML = '';
            if (matchedData.length === 0) {
                matchedList.innerHTML = '<div class="ocr-empty"><strong>No medicines yet</strong>Add some from the "+ Add Medicine" button below.</div>';
                return;
            }
            matchedData.forEach((m, idx) => matchedList.appendChild(buildCard(m, idx)));
        }

        function makeFallbackIcon() {
            const span = document.createElement('span');
            span.innerHTML = '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg"><g transform="rotate(-40 32 32)"><rect x="6" y="21" width="52" height="22" rx="11" fill="#ffffff" stroke="#078f86" stroke-width="2.5"/><path d="M32 21h15a11 11 0 0 1 0 22H32z" fill="#bfe5e0" stroke="#078f86" stroke-width="2.5" stroke-linejoin="round"/></g></svg>';
            return span.firstChild;
        }

        function buildCard(m, idx) {
            const card = document.createElement('div');
            card.className = 'ocr-mcard' + (m.is_restricted ? ' restricted' : '');

            const thumb = document.createElement('div');
            thumb.className = 'thumb';
            if (m.image_path) {
                const img = document.createElement('img');
                img.src = '../' + String(m.image_path).replace(/^\/+/, '');
                img.alt = m.medicine_name || 'Medicine';
                img.onerror = function () { this.replaceWith(makeFallbackIcon()); };
                thumb.appendChild(img);
            } else { thumb.appendChild(makeFallbackIcon()); }

            let badgeHtml = '';
            if (m._manual) badgeHtml = '<span class="match-badge manual">＋ manual</span>';
            else if (m.match_type === 'exact') badgeHtml = '<span class="match-badge exact">✓ exact</span>';
            else if (m.match_type === 'generic') badgeHtml = '<span class="match-badge generic">generic</span>';
            else if (m.match_type === 'fuzzy') badgeHtml = '<span class="match-badge fuzzy">~ ' + Math.round(m.score || 0) + '%</span>';

            const info = document.createElement('div');
            info.className = 'info';
            info.innerHTML =
                '<div class="name">' + escHtml(m.medicine_name) + ' ' + escHtml(m.strength || '') + ' ' + badgeHtml + '</div>' +
                '<div class="meta"><span>🏢 ' + escHtml(m.company_name || '—') + '</span><span>💊 ' + escHtml((m.dosage_form || '').replace(/_/g, ' ')) + '</span></div>' +
                '<div class="meta"><span>🏥 ' + escHtml(m.pharmacy_name || '—') + '</span><span>Stock: ' + (m.available_stock || 0) + '</span></div>' +
                (!m._manual && m.source_line ? '<div class="ocr-source">📝 Scanned: <em>' + escHtml(m.source_line) + '</em></div>' : '') +
                (m.is_restricted ? '<span class="restricted-tag">Restricted · needs approval</span>' : '');

            const qtyCol = document.createElement('div');
            qtyCol.className = 'qty-price-col';
            const maxQty = Math.max(1, m.available_stock || 1);
            qtyCol.innerHTML =
                '<div class="ocr-qty">' +
                '<button type="button" class="minus" ' + ((m.qty || 1) <= 1 ? 'disabled' : '') + '>−</button>' +
                '<input type="number" class="qty" min="1" max="' + maxQty + '" value="' + (m.qty || 1) + '">' +
                '<button type="button" class="plus">+</button>' +
                '</div>' +
                '<span class="unit">' + fmtTk(m.unit_price) + ' each</span>' +
                '<span class="total">' + fmtTk((m.unit_price || 0) * (m.qty || 1)) + '</span>';

            const xDel = document.createElement('button');
            xDel.type = 'button'; xDel.className = 'x-del'; xDel.title = 'Remove'; xDel.textContent = '×';

            const qtyInput = qtyCol.querySelector('.qty');
            const minusBtn = qtyCol.querySelector('.minus');
            const plusBtn = qtyCol.querySelector('.plus');
            const totalElLocal = qtyCol.querySelector('.total');
            function setQty(n) {
                n = Math.max(1, Math.min(maxQty, parseInt(n, 10) || 1));
                qtyInput.value = n; matchedData[idx].qty = n;
                minusBtn.disabled = n <= 1; plusBtn.disabled = n >= maxQty;
                totalElLocal.textContent = fmtTk((m.unit_price || 0) * n);
                updateTotals();
            }
            minusBtn.addEventListener('click', () => setQty(matchedData[idx].qty - 1));
            plusBtn.addEventListener('click', () => setQty(matchedData[idx].qty + 1));
            qtyInput.addEventListener('change', e => setQty(e.target.value));

            xDel.addEventListener('click', () => {
                matchedData.splice(idx, 1);
                rebuildMatchedList();
                updateTotals();
                if (searchOpen && searchInput.value.trim().length >= 2) doSearch(searchInput.value.trim());
            });

            card.appendChild(thumb); card.appendChild(info); card.appendChild(qtyCol); card.appendChild(xDel);
            return card;
        }

        function updateTotals() {
            let total = 0, items = 0;
            matchedData.forEach(m => { total += (m.unit_price || 0) * (m.qty || 1); items += (m.qty || 1); });
            totalEl.textContent = fmtTk(total);
            qtyNote.textContent = matchedData.length + ' medicine(s) · ' + items + ' unit(s)';
        }

        /* Add Medicine toggle with X inside */
        addToggle.addEventListener('click', (e) => {
            if (e.target === addClose) return;
            searchOpen = !searchOpen;
            if (searchOpen) {
                searchBox.classList.add('show'); addToggle.classList.add('open');
                setTimeout(() => searchInput.focus(), 120);
            } else { closeSearch(); }
        });
        addClose.addEventListener('click', (e) => { e.stopPropagation(); closeSearch(); });

        function closeSearch() {
            searchOpen = false;
            searchBox.classList.remove('show');
            searchResults.classList.remove('show');
            addToggle.classList.remove('open');
            searchInput.value = '';
            searchResults.innerHTML = '<div class="ocr-addhint">Type at least 2 letters to search the catalogue.</div>';
        }

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            const q = searchInput.value.trim();
            if (q.length < 2) { searchResults.classList.remove('show'); return; }
            searchTimer = setTimeout(() => doSearch(q), 200);
        });
        searchInput.addEventListener('focus', () => { if (searchInput.value.trim().length >= 2) searchResults.classList.add('show'); });
        document.addEventListener('click', e => { if (!searchBox.contains(e.target) && !addToggle.contains(e.target)) searchResults.classList.remove('show'); });

        function doSearch(q) {
            searchResults.classList.add('show');
            searchResults.innerHTML = '<div class="ocr-addhint">Searching…</div>';
            fetch('actions/ocr_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.ok) { searchResults.innerHTML = '<div class="ocr-addhint">Search failed.</div>'; return; }
                    renderSearchResults(data.results || []);
                }).catch(() => { searchResults.innerHTML = '<div class="ocr-addhint">Search failed.</div>'; });
        }

        function renderSearchResults(list) {
            searchResults.innerHTML = '';
            if (!list.length) { searchResults.innerHTML = '<div class="ocr-addhint">No matching medicine found.</div>'; return; }
            list.forEach(r => {
                const added = matchedData.some(m => m.medicine_id === r.medicine_id && m.pharmacy_id === r.pharmacy_id);
                const row = document.createElement('div');
                row.className = 'ocr-addrow' + (added ? ' added' : '');

                const thumb = document.createElement('div');
                thumb.className = 'thumb';
                if (r.image_path) {
                    const img = document.createElement('img');
                    img.src = '../' + String(r.image_path).replace(/^\/+/, '');
                    img.onerror = function () { this.replaceWith(makeFallbackIcon()); };
                    thumb.appendChild(img);
                } else { thumb.appendChild(makeFallbackIcon()); }

                const info = document.createElement('div');
                info.className = 'ar-info';
                info.innerHTML = '<div class="ar-name">' + escHtml(r.medicine_name) + ' <small>' + escHtml(r.strength || '') + '</small></div>' +
                    '<div class="ar-meta"><span>🏢 ' + escHtml(r.company_name || '—') + '</span><span>🏥 ' + escHtml(r.pharmacy_name || '—') + '</span></div>';

                const rightWrap = document.createElement('div');
                rightWrap.className = 'ar-right';
                const priceEl = document.createElement('div');
                priceEl.className = 'ar-price';
                priceEl.innerHTML = fmtTk(r.unit_price) + '<small>Stock: ' + r.available_stock + '</small>';
                const btn = document.createElement('button');
                btn.type = 'button'; btn.className = 'ar-add-btn'; btn.textContent = added ? '✓ Added' : '＋ Add';
                rightWrap.appendChild(priceEl); rightWrap.appendChild(btn);

                if (!added) {
                    const addFn = () => {
                        matchedData.push({
                            medicine_id: r.medicine_id, medicine_name: r.medicine_name, generic_name: r.generic_name,
                            strength: r.strength, dosage_form: r.dosage_form, company_name: r.company_name,
                            category_name: r.category_name, image_path: r.image_path, is_restricted: r.is_restricted,
                            pharmacy_id: r.pharmacy_id, pharmacy_name: r.pharmacy_name, unit_price: r.unit_price,
                            available_stock: r.available_stock, has_stock: true, qty: 1,
                            _manual: true, match_type: 'manual', score: 100
                        });
                        rebuildMatchedList(); updateTotals(); doSearch(searchInput.value.trim());
                    };
                    btn.addEventListener('click', e => { e.stopPropagation(); addFn(); });
                    row.addEventListener('click', addFn);
                }

                row.appendChild(thumb); row.appendChild(info); row.appendChild(rightWrap);
                searchResults.appendChild(row);
            });
        }

        scanAgain.addEventListener('click', () => {
            pendingFiles = []; prescriptionTokens = []; matchedData = [];
            closeSearch(); resetUI(); hideError();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        /* Buy button */
        buyBtn.addEventListener('click', () => {
            if (!matchedData.length) { showError('Please add at least one medicine.'); return; }
            const hasRestricted = matchedData.some(m => m.is_restricted === true);
            if (hasRestricted) { showApprovalModal(); }
            else { submitBuyForm(false); }
        });

        function submitBuyForm(isRestrictedFlow) {
            buyForm.innerHTML = '';
            let added = 0;
            matchedData.forEach(m => {
                if (!m.pharmacy_id || m.pharmacy_id <= 0) return;
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'items[]';
                input.value = m.medicine_id + ':' + m.pharmacy_id + ':' + (m.qty || 1);
                buyForm.appendChild(input);
                added++;
            });
            if (added === 0) { showError('No purchasable medicines were found.'); return; }

            if (isRestrictedFlow && prescriptionTokens.length) {
                const t = document.createElement('input');
                t.type = 'hidden'; t.name = 'prescription_tokens';
                t.value = JSON.stringify(prescriptionTokens);
                buyForm.appendChild(t);
            }
            buyBtn.disabled = true; buyBtn.textContent = 'Processing…';
            buyForm.submit();
        }

        function showApprovalModal() {
            const old = document.getElementById('ocrApprovalModal');
            if (old) old.remove();
            const modal = document.createElement('div');
            modal.id = 'ocrApprovalModal';
            modal.className = 'ocr-approval-overlay';
            modal.innerHTML = `
      <div class="ocr-approval-card" role="dialog" aria-modal="true">
        <button type="button" class="ocr-approval-close" aria-label="Close">&times;</button>
        <div class="ocr-approval-icon">⏳</div>
        <h2>Restricted Medicine — Approval Required</h2>
        <p class="ocr-approval-desc">Your cart contains <strong>restricted medicine(s)</strong>. A licensed pharmacist must verify your prescription before this order can be confirmed.</p>
        <div class="ocr-approval-info">
          <div class="ocr-approval-row"><span class="lbl">Estimated wait</span><span class="val">10 – 20 minutes</span></div>
          <div class="ocr-approval-row"><span class="lbl">Reason</span><span class="val">Prescription verification by licensed pharmacist</span></div>
          <div class="ocr-approval-row"><span class="lbl">What happens next</span><span class="val">Once verified, the medicines are automatically added to your cart.</span></div>
        </div>
        <div class="ocr-approval-actions">
          <button type="button" class="ocr-approval-btn ghost" id="ocrApprovalCancel">Cancel</button>
          <button type="button" class="ocr-approval-btn primary" id="ocrApprovalConfirm">Confirm &amp; Wait for Approval</button>
        </div>
      </div>
    `;
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
            const close = () => { modal.remove(); document.body.style.overflow = ''; };
            modal.querySelector('.ocr-approval-close').addEventListener('click', close);
            document.getElementById('ocrApprovalCancel').addEventListener('click', close);
            modal.addEventListener('click', e => { if (e.target === modal) close(); });
            document.getElementById('ocrApprovalConfirm').addEventListener('click', () => { close(); submitBuyForm(true); });
        }
    })();
</script>
<?php require_once __DIR__ . '/../common/footer.php'; ?>