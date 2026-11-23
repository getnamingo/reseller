<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact help@namingo.org.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

namespace Namingo\ResellerApi;

use DragonOfMercy\PhpPdf\{Document,Font,Color,Page,TextAlign,CellPadding};
use DragonOfMercy\PhpPdf\Table\{Column,TableStyle,TableBorders};

final class InvoiceDocument
{
    public static function party(array $data, bool $seller = false): string
    {
        return implode("\n", array_filter([
            $data[$seller ? 'name' : 'company'], $data['address'],
            $seller ? $data['postcode'].' '.$data['city'] : $data['billing_postcode'].' '.$data['billing_city'],
            $data[$seller ? 'country' : 'country_code'], 'Company no. '.$data['company_number'],
            empty($data['vat_number']) ? '' : 'VAT '.$data['vat_number'], $data[$seller ? 'email' : 'billing_email'],
        ],static fn($s)=>$s!==''));
    }
    public static function pdf(array $invoice): string
    {
        $pdf=new Document();
        $pdf->registerFontFamily('Invoice',Config::get('INVOICE_FONT_REGULAR','/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'),
            Config::get('INVOICE_FONT_BOLD','/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'));
        $font=Font::custom('Invoice'); $navy=Color::hex('183153'); $blue=Color::hex('206bc4'); $gray=Color::hex('62748b');
        $pdf->setDefaultFont($font,9)->setMargins(18)->setAutoPageBreak(true);
        $pdf->setFooter(static function(Page $page) use($invoice,$font,$gray): void {
            $page->setFont($font,8)->setFillColor($gray)->text(18,285,$invoice['number'].'  /  Page '.$page->pageNumber());
        });
        $page=$pdf->addPage();
        $page->setFillColor($navy)->rect(0,0,210,38)->fill();
        $page->setFillColor(Color::hex('ffffff'))->setFont($font->bold(),27)->text(18,23,'INVOICE');
        $page->setFont($font,11)->text(120,23,$invoice['number']);
        $page->setFillColor($blue)->rect(0,38,210,2)->fill();
        $page->setFont($font,9)->setFillColor($gray)->text(18,50,'ISSUED '.$invoice['issued_at']);
        $page->text(18,57,'SERVICE PERIOD '.$invoice['period_start'].' — '.$invoice['period_end']);
        $page->setFont($font->bold(),9)->setFillColor($blue)->text(18,71,'FROM')->text(108,71,'BILL TO');
        $page->setFont($font,9)->setFillColor($navy);
        $left=$page->cell(x:18,y:76,w:82,text:self::party($invoice['seller'],true),padding:0);
        $right=$page->cell(x:108,y:76,w:84,text:self::party($invoice['customer']),padding:0);
        $y=max($left->y,$right->y)+10;
        $rows=array_map(static fn($line)=>['date'=>$line['date'],'description'=>$line['description'],'qty'=>'1',
            'unit'=>$line['unit_net'],'net'=>$line['net']],$invoice['lines']);
        if ($rows===[]) { $rows=[['date'=>'—','description'=>'No billable service activity','qty'=>'0','unit'=>'0.0000','net'=>'0.0000']]; }
        $table=$page->table([Column::of('date','Supply date')->width(25),Column::of('description','Service')->fill(),Column::of('qty','Qty')->width(12),
            Column::of('unit','Unit net')->width(29)->align(TextAlign::RIGHT),Column::of('net','Net')->width(29)->align(TextAlign::RIGHT)],$rows,x:18,y:$y,width:174,
            style:TableStyle::default()->withRowPadding(CellPadding::symmetric(2.2,1.5))->withBorder(TableBorders::HORIZONTAL)->withHeader($navy,true,Color::hex('ffffff'))->withZebra(Color::hex('f2f6fb'),Color::hex('ffffff')));
        $page=$table->page; $y=$table->y+10;
        if ($y>225) { $page=$pdf->addPage(); $y=25; }
        $page->setFillColor($navy)->setFont($font,10);
        $page->cell(x:112,y:$y,w:80,text:'Subtotal   '.$invoice['net'].' '.$invoice['currency'],align:TextAlign::RIGHT,padding:0);
        $page->cell(x:112,y:$y+8,w:80,text:'VAT '.$invoice['vat_rate'].'%   '.$invoice['vat'].' '.$invoice['currency'],align:TextAlign::RIGHT,padding:0);
        $page->setFont($font->bold(),14)->setFillColor($blue);
        $page->cell(x:85,y:$y+19,w:107,text:'TOTAL   '.$invoice['total'].' '.$invoice['currency'],align:TextAlign::RIGHT,padding:0);
        $page->setFont($font,9)->setFillColor($gray);
        $note=$invoice['payment_note']."\n".$invoice['tax_note'];
        if ($invoice['tax_currency']!==$invoice['currency'] && $invoice['vat']!=='0.0000') {
            $note.="\nVAT in tax currency: ".$invoice['tax_currency_vat'].' '.$invoice['tax_currency'].' (1 '.$invoice['currency'].' = '.$invoice['tax_fx_rate'].' '.$invoice['tax_currency'].').';
        }
        $page->cell(x:18,y:$y+37,w:174,text:trim($note),padding:0);
        return $pdf->output();
    }
    public static function emailHtml(array $invoice): string
    {
        $view=new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__).'/panel/views'),['autoescape'=>'html','cache'=>false]);
        return $view->render('invoice_email.twig',['invoice'=>$invoice]);
    }
}
