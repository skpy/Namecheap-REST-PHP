<?php
// Namecheap API class

class namecheap
{
	// API credential information required to execute requests
	private $api_url;
	private $api_user;
	private $api_key;
	private $api_ip;
	// sotrage for API responses
	public $Response;
	public $Error;
	public $Raw;

	/*
	 * instantiate a namecheap object
	 * @credentials array associative array of namecheap API credentials
	 * @sandbox boolean whether to use the Namecheap Sandbox or the real site
	 * @return object a namecheap object
	*/
	public function __construct( $credentials, $sandbox = true )
	{
		if ( $sandbox ) { 
			$this->api_url = 'https://api.sandbox.namecheap.com/xml.response';
		} else {
			$this->api_url = 'https://api.namecheap.com/xml.response';
		}
		$this->api_user = $credentials['api_user'];
		$this->api_key = $credentials['api_key'];
		$this->api_ip = ( 'detect' == $credentials['api_ip'] ) ? $this->detect_ip() : $credentials['api_ip'];
	}

	/*
	 * determine our IP address
	 * @return string our public IP address, as seen by icanhazip.com
	 */
	public function detect_ip()
	{
		$ch = curl_init( 'http://icanhazip.com' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
		$result = curl_exec( $ch );
		curl_close( $ch );
		return $result;
	}

	/*
	 * execute a call to the Namecheap API
	 * @command string the name of the API call to invoke
	 * @args array associative array of options for the API call
	 * @return bool success or failure
	 */
	private function execute( $command, $args = array() )
	{
		// blank out any previous values for these
		$this->Error = '';
		$this->Response = '';
		$this->Raw = '';

		$url = $this->api_url . 
			'?ApiUser=' . $this->api_user . 
			'&ApiKey=' . $this->api_key . 
			'&UserName=' . $this->api_user . 
			'&ClientIP=' . $this->api_ip .
			'&Command=' . $command;
		foreach ( $args as $arg => $value ) 
		{
			$url .= "&$arg=";
			$url .= urlencode( $value );
		}
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
		$result = curl_exec( $ch );
		curl_close( $ch );
		if ( FALSE == $result ) {
			$this->Error = 'Communication error with Namecheap.';
			return FALSE;
		}
		$xml = new SimpleXMLElement( $result );
		$this->Raw = $xml;
		if ( 'ERROR' == $xml['Status'] )
		{
			$this->Error = (string) $xml->Errors->Error;
			return FALSE;
		} elseif ( 'OK' == $xml['Status'] )
		{
			$this->Response = $xml->CommandResponse;
			return true;
		}
	}

	/*
	 * check the availability of one or more domains
	 * @domains mixed array, comma delimited list, or single domain name
	 * @return mixed  associative array of domains => status, 
	 *	or boolean if only a single domain is being checked
	 */
	public function domainsCheck( $domains )
	{
		if ( is_array( $domains ) ) {
			$domains = implode( ',', $domains );
		}
		if ( ! $this->execute( 'namecheap.domains.check', array( 'DomainList' => $domains ) ) ) {
			// communication error
			return FALSE;
		}
		if ( FALSE === strpos( $domains, ',' ) ) {
			//only one domain was passed, so just return
			// the availability of that domain
			$status = ( 'true' == strtolower( (string)$this->Response->DomainCheckResult->attributes()->Available ) ) ? TRUE : FALSE;
			return $status;
		}
		$r = array();
		foreach ( $this->Response->DomainCheckResult as $result ) {
			$domain = (string)$result['Domain'];
			$status = ( 'true' == strtolower( (string)$result['Available'] ) ) ? TRUE : FALSE;
			$r[$domain] = $status;
		}
		return $r;
	}

	/*
	 * register a domain
	 * @domain string the domain name to register
	 * @data array associative array of required registration data
	 *	http://developer.namecheap.com/docs/doku.php?id=api-reference:domains:create
	 * @return bool success or failure of the registration
	 */
	public function domainsCreate( $domain, $data )
	{
		$data['DomainName'] = $domain;
		if ( ! $this->execute( 'namecheap.domains.create', $data ) ) {
			return FALSE;
		}
		if ( 'true' == strtolower( $this->Response->DomainCreateResult->attributes()->Registered ) )
		{
			return TRUE;
		}
		return FALSE;
	}

	/*
	 * return a list of domains we own
	 * @type string All, Expiring, or Expired. Defaults to All
	 * @page int page number to return
	 * @pagesize int number of domains per page. minimum 10, max 100.
	 * @sort string NAME, NAME_DESC, EXPIREDATE, EXPIREDATE_DESC, CREATEDATE, CREATEDATE_DESC
	 * @search string specific name for which to search
	 * @return mixed an array of domains or boolean false
	 */
	public function domainsGetList( $type = 'all', $page = 1, $pagesize = 100,  $sort = 'NAME', $search = '')
	{
		if ( ! $this->execute( 'namecheap.domains.getList', array( 'ListType' => $type, 'SearchTerm' => $search, 'Page' => $page, 'PageSize' => $pagesize, 'SortBy' => $sort ) ) ) {
			return FALSE;
		}
		$domains = array();
		foreach ( $this->Response->DomainGetListResult->Domain as $domain ) {
			$x = array();
			foreach( $domain->attributes() as $k => $v ) {
				$x[$k] = (string)$v;
			}
			$domains[] = $x;
		}
		return $domains;
	}

	/*
	 * create new nameservers
	 * @domain string domain name to which these nameservers will be assigned
	 * @nameserver string the FQDN of the nameserver to create
	 * @ip string the IP address of the nameserver to create
	 * @return bool success or failure
	 */
	public function nsCreate( $domain, $nameserver, $ip )
	{
		list( $sld, $tld ) = explode( '.', $domain );
		$args['SLD'] = $sld;
		$args['TLD'] = $tld;
		$args['Nameserver'] = $nameserver;
		$args['IP'] = $ip;
		if ( $this->execute( 'namecheap.domains.ns.create', $args ) ) {
			return TRUE;
		}
		return $false;
	}

	/*
	 * assign nameservers to a domain
	 * @domain string the domain name that will be assigned nameservers
	 * @nameservers mixed an array or comma delimited list of nameservers
	 * @return bool success or failure
	 */
	public function dnsSetCustom( $domain, $nameservers )
	{
		if ( is_array( $nameservers ) ) {
			$nameservers = implode( ',', $nameservers );
		}
		list( $sld, $tld ) = explode( '.', $domain );
		$args['SLD'] = $sld;
		$args['TLD'] = $tld;
		$args['NameServers'] = $nameservers;
		if ( ! $this->execute( 'namecheap.domains.dns.setCustom', $args ) ) {
			return FALSE;
		}
		if ( 'true' == strtolower( $this->Response->DomainDNSSetCustomResult->attributes()->Updated ) )
		{
			return TRUE;
		}
		return FALSE;
	}

	/*
	 * configure a domain to use Namecheap's default nameservers
	 * @domain string the domain to set
	 * @return bool success or failure
	 */
	public function dnsSetDefault( $domain )
	{
		list( $sld, $tld ) = explode( '.', $domain );
		if ( ! $this->execute( 'namecheap.domains.dns.SetDefault', array( 'SLD' => $sld, 'TLD' => $tld ) ) ) {
			return FALSE;
		}
		if ( 'true' == strtolower( $this->Response->DomainDNSSetDefaultResult->attributes()->Updated ) ) {
			return TRUE;
		}
		return FALSE;
	}

	/*
	 * get a list of DNS servers for a domain
	 * @domain string the domain to query
	 * @return mixed an array of nameservers, or boolean false
	 */
	public function dnsGetList( $domain )
	{
		list( $sld, $tld ) = explode( '.', $domain );
		if ( ! $this->execute( 'namecheap.domains.dns.getList', array( 'SLD' => $sld, 'TLD' => $tld ) ) ) {
			return FALSE;
		}
		$servers = array();
		foreach ( $this->Response->DomainDNSGetListResult->Nameserver as $ns ) {
			$servers[] = (string)$ns;
		}
		return $servers;
	}

	/*
	 * set DNS host records for the specified domain
	 * @domain string domain for which the record should be defined
	 * @data array associative array of record details to set
	 * @return bool success or failure
	 */
	public function dnsSetHosts( $domain, $data )
	{
		list( $data['SLD'], $data['TLD'] ) = explode( '.', $domain );
		if ( ! $this->execute( 'namecheap.domains.dns.setHosts', $data ) ) {
			return FALSE;
		}
		if ( 'true' == strtolower( $this->Response->DomainDNSSetHostsResult->attributes()->IsSuccess ) ) {
			return TRUE;
		}
		return FALSE;
	}

	/*
	 * return the balance of an account
	 * @return mixed an array of balance information, or boolean false
	 */
	public function usersGetBalances()
	{
		if( ! $this->execute( 'namecheap.users.getBalances' ) ) {
			return FALSE;
		}
		foreach ( $this->Response->UserGetBalancesResult->attributes() as $k => $v ) {
			$balance[$k] = (string)$v;
		}
		return $balance;
	}

	/*
	 * return pricing information for TLDs
	 * @type string one of DOMAIN, SSLCERTIFICATE or WHOISGUARD 
	 * @category string specific category within product type
	 * @promo string promotional code
	 * http://developer.namecheap.com/docs/doku.php?id=api-reference:users:getpricing
	 * @return mixed
	 */
	public function getPricing( $type = 'DOMAIN', $category ='', $promo = '' )
	{
		$args = array( 'ProductType' => $type );
		if ( ! empty( $category )  )
		{
			$args['ProductCategory'] = $category;
		}
		if ( ! empty( $promo ) )
		{
			$args['PromotionCode'] = $promo;
		}
		$this->execute( 'namecheap.users.getPricing', $args );
	}
}
