import { Controller, Get, Post, Body, Param, UseGuards, Request } from '@nestjs/common';
import { JwtAuthGuard } from '../auth/guards/jwt-auth.guard';
import { LocationsService } from './locations.service';

@Controller('locations')
export class LocationsController {
  constructor(private readonly locationsService: LocationsService) {}

  // Get all available locations (public)
  @Get()
  async getAllLocations() {
    return await this.locationsService.getAllLocations();
  }

  // Get specific location by slug (public)
  @Get(':slug')
  async getLocationBySlug(@Param('slug') slug: string) {
    return await this.locationsService.getLocationBySlug(slug);
  }

  // Get current user's location (protected)
  @UseGuards(JwtAuthGuard)
  @Get('current/location')
  async getCurrentLocation(@Request() req: any) {
    return await this.locationsService.getCurrentLocation(req.user.userId);
  }

  // Travel to a new location (protected)
  @UseGuards(JwtAuthGuard)
  @Post('travel')
  async travelToLocation(
    @Request() req: any,
    @Body('destination') destination: string,
  ) {
    return await this.locationsService.travelToLocation(req.user.userId, destination);
  }
}
