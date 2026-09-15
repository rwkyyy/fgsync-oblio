<?php
/**
 * Plugin bootstrap and service wiring.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo;

use OblioWoo\Admin\BulkActions;
use OblioWoo\Admin\ConnectionTest;
use OblioWoo\Admin\ImportAction;
use OblioWoo\Admin\LegacyNotice;
use OblioWoo\Admin\LogReader;
use OblioWoo\Admin\LogTailAction;
use OblioWoo\Admin\NomenclatureCache;
use OblioWoo\Admin\OrderActions;
use OblioWoo\Admin\OrderListColumn;
use OblioWoo\Admin\OrderListFilter;
use OblioWoo\Admin\OrderMetaBox;
use OblioWoo\Admin\Privacy;
use OblioWoo\Admin\ProductFields;
use OblioWoo\Admin\QueueStatus;
use OblioWoo\Admin\SettingsPage;
use OblioWoo\Admin\SettingsShortcut;
use OblioWoo\Admin\StatusPanel;
use OblioWoo\Admin\StockSyncAction;
use OblioWoo\Admin\UpdateChecker;
use OblioWoo\Api\ClientFactory;
use OblioWoo\Compat\OrderStore;
use OblioWoo\Frontend\AccountInvoices;
use OblioWoo\Legacy\Importer;
use OblioWoo\Extensibility\HookInspector;
use OblioWoo\Extensibility\HookRegistry;
use OblioWoo\Document\DocumentService;
use OblioWoo\Document\EmailButton;
use OblioWoo\Document\InvoiceBuilder;
use OblioWoo\Document\InvoiceEmailer;
use OblioWoo\Document\LifecyclePolicy;
use OblioWoo\Document\Mapper\ClientMapper;
use OblioWoo\Document\Mapper\CollectMapper;
use OblioWoo\Document\Mapper\LineItemMapper;
use OblioWoo\Document\Mapper\ShippingFeeMapper;
use OblioWoo\Queue\AutoIssue;
use OblioWoo\Queue\Jobs\GenerateDocument;
use OblioWoo\Queue\Jobs\GenerateRefund;
use OblioWoo\Queue\Reconciler;
use OblioWoo\Queue\Scheduler;
use OblioWoo\Queue\Jobs\ProcessWebhookEvent;
use OblioWoo\Queue\Jobs\StockSyncBatch;
use OblioWoo\Refund\RefundAutoIssue;
use OblioWoo\Refund\RefundService;
use OblioWoo\Returns\ReturnsIntegration;
use OblioWoo\Stock\LocationAggregator;
use OblioWoo\Stock\ProductUpdater;
use OblioWoo\Stock\StockReservations;
use OblioWoo\Stock\StockSyncCoordinator;
use OblioWoo\Support\Container;
use OblioWoo\Webhook\Handler\CollectInsertedHandler;
use OblioWoo\Webhook\Handler\StockHandler;
use OblioWoo\Webhook\RestController;
use OblioWoo\Webhook\TopicRegistry;
use OblioWoo\Webhook\WebhookManager;
use OblioWoo\Support\Encryption;
use OblioWoo\Support\Logger;
use OblioWoo\Support\RateLimiter;
use OblioWoo\Support\Settings;
final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	public function get( string $id ): object {
		return $this->container->get( $id );
	}

	private function register_services(): void {
		$container = $this->container;

		$container->set(
			Settings::class,
			static fn (): Settings => new Settings()
		);

		$container->set(
			Logger::class,
			static fn (): Logger => new Logger()
		);

		$container->set(
			Encryption::class,
			static fn (): Encryption => new Encryption()
		);

		$container->set(
			RateLimiter::class,
			static fn (): RateLimiter => new RateLimiter()
		);

		$container->set(
			OrderStore::class,
			static fn (): OrderStore => new OrderStore()
		);

		$container->set(
			ClientFactory::class,
			static fn ( Container $container ): ClientFactory => new ClientFactory(
				$container->get( Settings::class ),
				$container->get( Encryption::class ),
				$container->get( Logger::class )
			)
		);

		$container->set( ClientMapper::class, static fn (): ClientMapper => new ClientMapper() );
		$container->set( LineItemMapper::class, static fn (): LineItemMapper => new LineItemMapper() );
		$container->set( ShippingFeeMapper::class, static fn (): ShippingFeeMapper => new ShippingFeeMapper() );
		$container->set( CollectMapper::class, static fn ( Container $container ): CollectMapper => new CollectMapper( $container->get( Settings::class ) ) );
		$container->set( LifecyclePolicy::class, static fn ( Container $container ): LifecyclePolicy => new LifecyclePolicy( $container->get( Settings::class ) ) );
		$container->set(
			InvoiceEmailer::class,
			static fn ( Container $container ): InvoiceEmailer => new InvoiceEmailer(
				$container->get( Settings::class ),
				$container->get( Logger::class )
			)
		);
		$container->set(
			EmailButton::class,
			static fn ( Container $container ): EmailButton => new EmailButton(
				$container->get( Settings::class ),
				$container->get( DocumentService::class ),
				$container->get( Logger::class ),
				$container->get( RateLimiter::class )
			)
		);

		$container->set(
			InvoiceBuilder::class,
			static fn ( Container $container ): InvoiceBuilder => new InvoiceBuilder(
				$container->get( Settings::class ),
				$container->get( ClientMapper::class ),
				$container->get( LineItemMapper::class ),
				$container->get( ShippingFeeMapper::class ),
				$container->get( CollectMapper::class )
			)
		);

		$container->set(
			DocumentService::class,
			static fn ( Container $container ): DocumentService => new DocumentService(
				$container->get( Settings::class ),
				$container->get( ClientFactory::class ),
				$container->get( InvoiceBuilder::class ),
				$container->get( LifecyclePolicy::class ),
				$container->get( InvoiceEmailer::class ),
				$container->get( Logger::class )
			)
		);

		$container->set( Scheduler::class, static fn (): Scheduler => new Scheduler() );

		$container->set(
			GenerateDocument::class,
			static fn ( Container $container ): GenerateDocument => new GenerateDocument(
				$container->get( DocumentService::class ),
				$container->get( OrderStore::class ),
				$container->get( Scheduler::class ),
				$container->get( Logger::class ),
				$container->get( RateLimiter::class )
			)
		);

		$container->set(
			AutoIssue::class,
			static fn ( Container $container ): AutoIssue => new AutoIssue(
				$container->get( Settings::class ),
				$container->get( Scheduler::class ),
				$container->get( OrderStore::class )
			)
		);

		$container->set(
			Reconciler::class,
			static fn ( Container $container ): Reconciler => new Reconciler(
				$container->get( Settings::class ),
				$container->get( Scheduler::class ),
				$container->get( OrderStore::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			RefundService::class,
			static fn ( Container $container ): RefundService => new RefundService(
				$container->get( Settings::class ),
				$container->get( ClientFactory::class ),
				$container->get( OrderStore::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			GenerateRefund::class,
			static fn ( Container $container ): GenerateRefund => new GenerateRefund(
				$container->get( RefundService::class ),
				$container->get( OrderStore::class ),
				$container->get( Scheduler::class ),
				$container->get( Logger::class ),
				$container->get( RateLimiter::class )
			)
		);

		$container->set(
			RefundAutoIssue::class,
			static fn ( Container $container ): RefundAutoIssue => new RefundAutoIssue(
				$container->get( Settings::class ),
				$container->get( Scheduler::class ),
				$container->get( OrderStore::class )
			)
		);

		$container->set( LocationAggregator::class, static fn (): LocationAggregator => new LocationAggregator() );
		$container->set( ProductUpdater::class, static fn ( Container $container ): ProductUpdater => new ProductUpdater( $container->get( Logger::class ) ) );
		$container->set( StockReservations::class, static fn (): StockReservations => new StockReservations() );

		$container->set(
			StockSyncBatch::class,
			static fn ( Container $container ): StockSyncBatch => new StockSyncBatch(
				$container->get( Settings::class ),
				$container->get( ClientFactory::class ),
				$container->get( Scheduler::class ),
				$container->get( LocationAggregator::class ),
				$container->get( ProductUpdater::class ),
				$container->get( StockReservations::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			StockSyncCoordinator::class,
			static fn ( Container $container ): StockSyncCoordinator => new StockSyncCoordinator(
				$container->get( Settings::class ),
				$container->get( Scheduler::class ),
				$container->get( StockReservations::class ),
				$container->get( Logger::class ),
				$container->get( StockSyncBatch::class )
			)
		);

		$container->set(
			StockSyncAction::class,
			static fn ( Container $container ): StockSyncAction => new StockSyncAction(
				$container->get( StockSyncCoordinator::class ),
				$container->get( Settings::class )
			)
		);

		$container->set(
			CollectInsertedHandler::class,
			static fn ( Container $container ): CollectInsertedHandler => new CollectInsertedHandler(
				$container->get( OrderStore::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			StockHandler::class,
			static fn ( Container $container ): StockHandler => new StockHandler(
				$container->get( StockSyncCoordinator::class )
			)
		);

		$container->set(
			TopicRegistry::class,
			static function ( Container $container ): TopicRegistry {
				$registry = new TopicRegistry();
				$registry->register( 'Collect/Inserted', $container->get( CollectInsertedHandler::class ) );
				$registry->register( 'stock', $container->get( StockHandler::class ) );
				return $registry;
			}
		);

		$container->set(
			RestController::class,
			static fn ( Container $container ): RestController => new RestController(
				$container->get( Settings::class ),
				$container->get( Scheduler::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			WebhookManager::class,
			static fn ( Container $container ): WebhookManager => new WebhookManager(
				$container->get( Settings::class ),
				$container->get( ClientFactory::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			ProcessWebhookEvent::class,
			static fn ( Container $container ): ProcessWebhookEvent => new ProcessWebhookEvent(
				$container->get( TopicRegistry::class ),
				$container->get( Logger::class )
			)
		);

		$container->set( SettingsShortcut::class, static fn (): SettingsShortcut => new SettingsShortcut() );
		$container->set( Privacy::class, static fn (): Privacy => new Privacy() );
		$container->set( LegacyNotice::class, static fn (): LegacyNotice => new LegacyNotice() );

		$container->set( HookRegistry::class, static fn (): HookRegistry => new HookRegistry() );
		$container->set( HookInspector::class, static fn ( Container $container ): HookInspector => new HookInspector( $container->get( HookRegistry::class ) ) );
		$container->set( LogReader::class, static fn (): LogReader => new LogReader() );
		$container->set( LogTailAction::class, static fn ( Container $container ): LogTailAction => new LogTailAction( $container->get( LogReader::class ) ) );
		$container->set( QueueStatus::class, static fn (): QueueStatus => new QueueStatus() );
		$container->set( UpdateChecker::class, static fn (): UpdateChecker => new UpdateChecker() );

		$container->set(
			NomenclatureCache::class,
			static fn ( Container $container ): NomenclatureCache => new NomenclatureCache(
				$container->get( ClientFactory::class ),
				$container->get( Settings::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			StatusPanel::class,
			static fn ( Container $container ): StatusPanel => new StatusPanel(
				$container->get( Settings::class ),
				$container->get( LogReader::class ),
				$container->get( QueueStatus::class ),
				$container->get( UpdateChecker::class ),
				$container->get( HookInspector::class )
			)
		);

		$container->set(
			SettingsPage::class,
			static fn ( Container $container ): SettingsPage => new SettingsPage(
				$container->get( Settings::class ),
				$container->get( ClientFactory::class ),
				$container->get( NomenclatureCache::class ),
				$container->get( StatusPanel::class ),
				$container->get( HookRegistry::class ),
				$container->get( HookInspector::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			OrderActions::class,
			static fn ( Container $container ): OrderActions => new OrderActions(
				$container->get( DocumentService::class ),
				$container->get( RefundService::class ),
				$container->get( OrderStore::class ),
				$container->get( Logger::class )
			)
		);
		$container->set( OrderMetaBox::class, static fn ( Container $container ): OrderMetaBox => new OrderMetaBox( $container->get( Settings::class ) ) );
		$container->set( ProductFields::class, static fn (): ProductFields => new ProductFields() );
		$container->set( OrderListColumn::class, static fn ( Container $container ): OrderListColumn => new OrderListColumn( $container->get( OrderStore::class ) ) );
		$container->set( OrderListFilter::class, static fn (): OrderListFilter => new OrderListFilter() );
		$container->set(
			BulkActions::class,
			static fn ( Container $container ): BulkActions => new BulkActions(
				$container->get( Settings::class ),
				$container->get( Scheduler::class ),
				$container->get( OrderStore::class ),
				$container->get( Logger::class )
			)
		);
		$container->set( AccountInvoices::class, static fn (): AccountInvoices => new AccountInvoices() );

		$container->set(
			Importer::class,
			static fn ( Container $container ): Importer => new Importer(
				$container->get( Settings::class ),
				$container->get( ClientFactory::class ),
				$container->get( Logger::class )
			)
		);
		$container->set( ImportAction::class, static fn ( Container $container ): ImportAction => new ImportAction( $container->get( Importer::class ) ) );

		$container->set(
			ReturnsIntegration::class,
			static fn ( Container $container ): ReturnsIntegration => new ReturnsIntegration(
				$container->get( Settings::class ),
				$container->get( RefundService::class ),
				$container->get( OrderStore::class ),
				$container->get( Logger::class )
			)
		);

		$container->set(
			ConnectionTest::class,
			static fn ( Container $container ): ConnectionTest => new ConnectionTest(
				$container->get( ClientFactory::class ),
				$container->get( NomenclatureCache::class ),
				$container->get( Logger::class )
			)
		);
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->get( GenerateDocument::class )->register();
		$this->get( GenerateRefund::class )->register();
		$this->get( StockSyncBatch::class )->register();
		$this->get( ProcessWebhookEvent::class )->register();
		$this->get( AutoIssue::class )->register();
		$this->get( RefundAutoIssue::class )->register();
		$this->get( Reconciler::class )->register();
		$this->get( StockSyncCoordinator::class )->register();
		$this->get( RestController::class )->register();
		$this->get( WebhookManager::class )->register();
		$this->get( AccountInvoices::class )->register();
		$this->get( ReturnsIntegration::class )->register();
		$this->get( EmailButton::class )->register();
		$this->get( ClientFactory::class )->register();

		$settings = $this->get( Settings::class );

		if ( false === get_transient( Scheduler::SCHEDULE_CHECK ) ) {
			$scheduler = $this->get( Scheduler::class );
			$scheduler->ensure_reconcile_scheduled(
				$settings->is_enabled( 'invoice_autogen' ),
				'batch' === (string) $settings->get( 'invoice_generation', 'event' )
					? (string) $settings->get( 'invoice_batch_interval', 'hourly' )
					: 'hourly'
			);
			$scheduler->ensure_stock_scheduled(
				$settings->stock_schedule_enabled(),
				(string) $settings->get( 'stock_interval', 'hourly' )
			);
			set_transient( Scheduler::SCHEDULE_CHECK, 1, HOUR_IN_SECONDS );
		}

		if ( is_admin() ) {
			if ( ! get_option( 'oblio_fgwoo_webhook_stock_removed' ) ) {
				update_option( 'oblio_fgwoo_webhook_stock_removed', 1, false );
				add_action( 'shutdown', fn () => $this->get( WebhookManager::class )->reconcile() );
			}

			$this->get( NomenclatureCache::class )->register();
			$this->get( SettingsPage::class )->register();
			$this->get( SettingsShortcut::class )->register();
			$this->get( Privacy::class )->register();
			$this->get( LegacyNotice::class )->register();
			$this->get( ConnectionTest::class )->register();
			$this->get( StockSyncAction::class )->register();
			$this->get( LogTailAction::class )->register();
			$this->get( OrderActions::class )->register();
			$this->get( OrderMetaBox::class )->register();
			$this->get( ProductFields::class )->register();
			$this->get( OrderListColumn::class )->register();
			$this->get( OrderListFilter::class )->register();
			$this->get( BulkActions::class )->register();
			$this->get( ImportAction::class )->register();
		}

		do_action( 'oblio_fgwoo_booted', $this );
	}

	public function activate(): void {
		add_option( 'oblio_fgwoo_version', OBLIO_FGWOO_VERSION );
		update_option( 'oblio_fgwoo_activated_at', time(), false );

		update_option( 'oblio_fgwoo_flush_rewrite', 1, false );
	}

	public function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'oblio' );
		}
	}
}
