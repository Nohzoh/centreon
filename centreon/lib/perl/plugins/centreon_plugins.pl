
use strict;
use warnings;
use centreon::plugins::script;

centreon::plugins::script->new()->run();

__END__

=head1 NAME

centreon_plugins.pl - main program to call Merethis plugins.

=head1 SYNOPSIS

centreon_plugins.pl [options]

=head1 OPTIONS

=over 8

=item B<--plugin>

Specify the path to the plugin.

=item B<--version>

Print plugin version.

=item B<--help>

Print a brief help message and exits.

=back

=head1 DESCRIPTION

B<centreon_plugins.pl> .

=cut


