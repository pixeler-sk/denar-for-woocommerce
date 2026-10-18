<?php

namespace Denar\WooCommerce\Tests;

use Denar\WooCommerce\Email\InvoiceAttachment;
use PHPUnit\Framework\TestCase;

/**
 * Which e-mails carry a Denár PDF and how the file is named.
 */
final class InvoiceAttachmentTest extends TestCase {

	public function test_completed_order_and_manual_invoice_carry_the_invoice(): void {
		$this->assertSame( 'invoice', InvoiceAttachment::role_for( 'customer_completed_order' ) );
		$this->assertSame( 'invoice', InvoiceAttachment::role_for( 'customer_invoice' ) );
	}

	public function test_other_emails_carry_nothing(): void {
		// Processing and on-hold e-mails go out in the checkout - the document is created later by the queue.
		$this->assertNull( InvoiceAttachment::role_for( 'customer_processing_order' ) );
		$this->assertNull( InvoiceAttachment::role_for( 'customer_on_hold_order' ) );
		$this->assertNull( InvoiceAttachment::role_for( 'new_order' ) );
	}

	public function test_file_name_follows_type_and_number(): void {
		$this->assertSame( 'Faktura-E2-20260003.pdf', InvoiceAttachment::file_name( 'invoice', 'E2-20260003' ) );
		$this->assertSame( 'Dobropis-DB20260001.pdf', InvoiceAttachment::file_name( 'credit_note', 'DB20260001' ) );
		$this->assertSame( 'Zalohova-faktura-ZF-2026-7.pdf', InvoiceAttachment::file_name( 'advance', 'ZF/2026/7' ) );
		$this->assertSame( 'Faktura.pdf', InvoiceAttachment::file_name( 'invoice', '' ) );
	}
}
